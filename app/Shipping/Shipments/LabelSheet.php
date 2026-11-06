<?php

namespace App\Shipping\Shipments;

use App\Models\LabelBatch;
use App\Models\Lead;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Models\User;
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
 * "Cetak label": the label PDF of each parcel merged into one PDF (every page at its own size, in the order given),
 * optionally after a packing list of the run. Labels come from the kept file (App\Shipping\Shipments\LabelStore),
 * else are downloaded from the provider (several at once) and kept. Each run is a App\Models\LabelBatch: the file
 * is private, named at random, opened through a signed link for members of the agency, and deleted after
 * config('carriers.label_batch_keep_days') by shipping:labels-prune. The parcels printed are marked printed.
 */
class LabelSheet
{
    public const DIR = 'label-sheets';

    /** Downloads at the same time. */
    private const CONCURRENCY = 8;

    public function __construct(private Shipments $shipments, private CurrentTenant $current, private LabelStore $store) {}

    /**
     * The newest parcel of each lead (the lead list's bulk "Cetak label").
     *
     * @param  iterable<Lead>  $leads
     * @return array{url: ?string, merged: list<string>, skipped: list<array{name: string, reason: string}>, printed: list<int>, batch: ?LabelBatch}
     */
    public function make(iterable $leads, ?User $by = null, bool $packingList = false): array
    {
        $parcels = [];
        $skipped = [];

        foreach ($leads as $lead) {
            $shipment = Shipment::latestFor($lead);

            if (blank($shipment?->awb)) {
                $skipped[] = ['name' => $lead->name, 'reason' => 'belum ada resi'];
            } else {
                $shipment->setRelation('lead', $lead);
                $parcels[] = $shipment;
            }
        }

        $sheet = $this->print($parcels, $by, $packingList);
        $sheet['skipped'] = [...$skipped, ...$sheet['skipped']];

        return $sheet;
    }

    /**
     * @param  iterable<Shipment>  $shipments  in the order they are printed
     * @return array{url: ?string, merged: list<string>, skipped: list<array{name: string, reason: string}>, printed: list<int>, batch: ?LabelBatch}
     */
    public function print(iterable $shipments, ?User $by = null, bool $packingList = false): array
    {
        $tenant = $this->current->get();
        $max = (int) config('carriers.label_max', 100);
        $wanted = [];
        $skipped = [];

        foreach ($shipments as $shipment) {
            $name = (string) $shipment->lead?->name;

            if (blank($shipment->awb) || $shipment->manual()) {
                $skipped[] = ['name' => $name, 'reason' => $shipment->manual() ? 'resi dari luar, tanpa label' : 'belum ada resi'];
            } elseif (count($wanted) >= $max) {
                $skipped[] = ['name' => $name, 'reason' => "lebih dari {$max} label sekali cetak"];
            } else {
                $wanted[] = $shipment;
            }
        }

        $files = [];
        $urls = [];

        foreach ($wanted as $i => $shipment) {
            if (($kept = $this->store->stored($shipment)) !== null) {
                $files[$i] = $kept;

                continue;
            }

            try {
                $urls[$i] = $this->shipments->label($shipment);
            } catch (CarrierException) {
                $urls[$i] = null;
            }
        }

        foreach ($this->download(array_filter($urls)) as $i => $body) {
            $files[$i] = $body;
            $this->store->keep($wanted[$i], $body);
        }

        $labels = new Fpdi;
        $labels->SetAutoPageBreak(false);
        $merged = [];
        $printed = [];

        foreach ($wanted as $i => $shipment) {
            $body = $files[$i] ?? null;

            if ($body === null || ! $this->append($labels, $body)) {
                Log::warning('Shipping label could not be fetched for the bulk print', ['tenant' => $tenant?->getKey(), 'lead' => $shipment->lead_id,
                    'step' => array_key_exists($i, $urls) && $urls[$i] === null ? 'link' : ($body === null ? 'download' : 'read')]);
                $skipped[] = ['name' => (string) $shipment->lead?->name, 'reason' => 'label gagal diambil'];

                continue;
            }

            $merged[] = $shipment->lead?->name.' · '.$shipment->awb;
            $printed[] = $shipment;
        }

        if ($printed === [] || $tenant === null) {
            return ['url' => null, 'merged' => [], 'skipped' => $skipped, 'printed' => [], 'batch' => null];
        }

        $pdf = $packingList ? $this->withPackingList($printed, $labels, $tenant) : $labels->Output('S');
        $name = Str::random(40).'.pdf';
        Storage::disk('local')->put(self::DIR.'/'.$tenant->getKey().'/'.$name, $pdf);

        $batch = LabelBatch::query()->create(['user_id' => $by?->getKey(), 'count' => count($printed), 'packing_list' => $packingList, 'file' => $name,
            'shipment_ids' => array_map(fn (Shipment $s) => $s->getKey(), $printed)]);

        foreach ($printed as $shipment) {
            $shipment->forceFill(['printed_at' => now(), 'printed_by' => $by?->getKey(), 'print_count' => $shipment->print_count + 1])->save();
        }

        return ['url' => self::link($tenant, $name), 'merged' => $merged, 'skipped' => $skipped, 'printed' => array_map(fn (Shipment $s) => $s->getKey(), $printed), 'batch' => $batch];
    }

    public static function link(Tenant $tenant, string $name): string
    {
        return URL::temporarySignedRoute('shipping.labels', now()->addHour(), ['tenant' => $tenant->getKey(), 'file' => $name]);
    }

    /**
     * A packing list of the run on A4 (no, buyer, product, variant, quantity, courier, tracking number, COD), then
     * the labels.
     *
     * @param  list<Shipment>  $shipments
     */
    private function withPackingList(array $shipments, Fpdi $labels, Tenant $tenant): string
    {
        $pdf = new Fpdi;
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage('P', 'A4');
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->Cell(0, 8, self::latin('Daftar pengemasan · '.$tenant->name), 0, 1);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(0, 5, self::latin(now($tenant->timezone ?: 'Asia/Jakarta')->format('d M Y H:i').' · '.count($shipments).' paket'), 0, 1);
        $pdf->Ln(2);

        $cols = [['No', 8], ['Pembeli', 34], ['Produk', 46], ['Varian', 22], ['Jml', 9], ['Kurir', 22], ['Resi', 32], ['COD', 17]];
        $pdf->SetFont('Helvetica', 'B', 8);
        foreach ($cols as [$label, $w]) {
            $pdf->Cell($w, 6, $label, 1);
        }
        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', 8);

        foreach ($shipments as $n => $shipment) {
            $lead = $shipment->lead;
            $items = $lead ? $this->shipments->items($lead) : [];
            $row = [
                (string) ($n + 1), (string) $lead?->name,
                implode(', ', array_map(fn ($i) => $i['name'], $items)) ?: '-',
                (string) ($lead?->variant ?: '-'),
                (string) (array_sum(array_column($items, 'quantity')) ?: ($lead?->quantity ?? '-')),
                (string) $shipment->courier, (string) $shipment->awb,
                $shipment->is_cod ? 'Rp'.number_format((int) $shipment->cod_amount, 0, ',', '.') : '-',
            ];

            foreach ($cols as $c => [, $w]) {
                $pdf->Cell($w, 6, self::fit($pdf, self::latin($row[$c]), $w - 1), 1);
            }
            $pdf->Ln();
        }

        // The labels after it, each page at its own size.
        try {
            $count = $pdf->setSourceFile(StreamReader::createByString($labels->Output('S')));

            for ($page = 1; $page <= $count; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $pdf->Output('S');
    }

    /** FPDF's core fonts are Latin-1. */
    private static function latin(string $text): string
    {
        $out = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);

        return $out === false ? preg_replace('/[^\x20-\x7E]/', '', $text) : $out;
    }

    private static function fit(Fpdi $pdf, string $text, float $width): string
    {
        while ($text !== '' && $pdf->GetStringWidth($text) > $width) {
            $text = mb_substr($text, 0, -2, '8bit').'.';
        }

        return $text;
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
