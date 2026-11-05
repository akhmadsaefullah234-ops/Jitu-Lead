<?php

namespace App\Shipping\Shipments;

use App\Models\Lead;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Shipping\Carriers\CarrierException;
use App\Support\CurrentTenant;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

/**
 * Bulk "Cetak label": the provider's label PDF of each selected lead, downloaded on the server and merged into one
 * PDF (every page at its own size, in the order given). The file is private, named at random, opened through a
 * signed link for members of the agency, and deleted after config('carriers.label_keep_hours') by shipping:labels-prune.
 */
class LabelSheet
{
    public const DIR = 'label-sheets';

    /** Downloads at the same time. */
    private const CONCURRENCY = 8;

    public function __construct(private Shipments $shipments, private CurrentTenant $current) {}

    /**
     * @param  iterable<Lead>  $leads
     * @return array{url: ?string, merged: list<string>, skipped: list<array{name: string, reason: string}>}
     */
    public function make(iterable $leads): array
    {
        $tenant = $this->current->get();
        $max = (int) config('carriers.label_max', 100);
        $wanted = [];
        $skipped = [];

        foreach ($leads as $lead) {
            $shipment = Shipment::latestFor($lead);

            if (blank($shipment?->awb)) {
                $skipped[] = ['name' => $lead->name, 'reason' => 'belum ada resi'];
            } elseif (count($wanted) >= $max) {
                $skipped[] = ['name' => $lead->name, 'reason' => "lebih dari {$max} label sekali cetak"];
            } else {
                $wanted[] = [$lead, $shipment];
            }
        }

        $urls = [];

        foreach ($wanted as $i => [$lead, $shipment]) {
            try {
                $urls[$i] = $this->shipments->label($shipment);
            } catch (CarrierException) {
                $urls[$i] = null;
            }
        }

        $files = $this->download(array_filter($urls));
        $pdf = new Fpdi;
        $pdf->SetAutoPageBreak(false);
        $merged = [];

        foreach ($wanted as $i => [$lead, $shipment]) {
            $body = $files[$i] ?? null;

            if ($body === null || ! $this->append($pdf, $body)) {
                Log::warning('Shipping label could not be fetched for the bulk print', ['tenant' => $tenant?->getKey(), 'lead' => $lead->getKey(),
                    'step' => $urls[$i] === null ? 'link' : ($body === null ? 'download' : 'read')]);
                $skipped[] = ['name' => $lead->name, 'reason' => 'label gagal diambil'];

                continue;
            }

            $merged[] = $lead->name.' · '.$shipment->awb;
        }

        if ($merged === [] || $tenant === null) {
            return ['url' => null, 'merged' => [], 'skipped' => $skipped];
        }

        $name = Str::random(40).'.pdf';
        Storage::disk('local')->put(self::DIR.'/'.$tenant->getKey().'/'.$name, $pdf->Output('S'));

        return ['url' => self::link($tenant, $name), 'merged' => $merged, 'skipped' => $skipped];
    }

    public static function link(Tenant $tenant, string $name): string
    {
        return URL::temporarySignedRoute('shipping.labels', now()->addHour(), ['tenant' => $tenant->getKey(), 'file' => $name]);
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<int, string> the PDF bytes of each label that came
     */
    private function download(array $urls): array
    {
        $out = [];
        $timeout = (int) config('carriers.label_timeout', 15);

        foreach (array_chunk($urls, self::CONCURRENCY, preserve_keys: true) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => collect($chunk)
                ->map(fn (string $url, int $i) => $pool->as((string) $i)->timeout($timeout)->connectTimeout(min(5, $timeout))->accept('application/pdf')->get($url))
                ->all());

            foreach ($responses as $i => $response) {
                if ($response instanceof Response && $response->successful() && str_starts_with(ltrim($response->body()), '%PDF')) {
                    $out[(int) $i] = $response->body();
                }
            }
        }

        return $out;
    }

    /** Every page of one label PDF at its own size; nothing is added when the file cannot be read. */
    private function append(Fpdi $pdf, string $body): bool
    {
        try {
            $pages = $pdf->setSourceFile(StreamReader::createByString($body));
            $templates = [];

            for ($page = 1; $page <= $pages; $page++) {
                $templates[] = $pdf->importPage($page);
            }
        } catch (Throwable) {
            return false;
        }

        foreach ($templates as $template) {
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
        }

        return $templates !== [];
    }
}
