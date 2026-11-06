<?php

namespace App\Shipping\Shipments;

use App\Models\Shipment;
use App\Shipping\Carriers\CarrierException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Each parcel's label PDF, kept on the private disk once its tracking number comes
 * (labels/{tenant}/{date}/{awb}.pdf, config('carriers.label_store_days') days), so printing is quick and works after
 * the provider's link has expired. A label not kept yet, or deleted, is fetched from the provider again.
 */
class LabelStore
{
    public const DIR = 'labels';

    public function __construct(private Shipments $shipments) {}

    /** Downloads and keeps the label. False when the provider has none yet (tried again by App\Jobs\SaveShipmentLabel). */
    public function save(Shipment $shipment): bool
    {
        if (! $shipment->printable()) {
            return false;
        }

        $body = $this->download($shipment);
        $shipment->forceFill(['label_attempts' => $shipment->label_attempts + 1])->save();

        if ($body === null) {
            return false;
        }

        $this->keep($shipment, $body);

        return true;
    }

    /** Keeps label bytes already downloaded (a bulk print fetches several at once). */
    public function keep(Shipment $shipment, string $body): void
    {
        $path = self::DIR.'/'.$shipment->tenant_id.'/'.($shipment->created_at ?? now())->format('Y-m-d').'/'.preg_replace('/[^A-Z0-9_-]/', '', strtoupper((string) $shipment->awb)).'.pdf';
        Storage::disk('local')->put($path, $body);
        $shipment->forceFill(['label_path' => $path, 'label_saved_at' => now(), 'label_failed_at' => null])->save();
    }

    /** The kept file, when it is still there. */
    public function stored(Shipment $shipment): ?string
    {
        $disk = Storage::disk('local');

        return filled($shipment->label_path) && $disk->exists($shipment->label_path) ? $disk->get($shipment->label_path) : null;
    }

    /** The label PDF: the kept file, else fetched from the provider (and kept). Null when neither has it. */
    public function bytes(Shipment $shipment): ?string
    {
        return $this->stored($shipment) ?? ($this->save($shipment) ? $this->stored($shipment) : null);
    }

    private function download(Shipment $shipment): ?string
    {
        try {
            $url = $this->shipments->label($shipment);
            $timeout = (int) config('carriers.label_timeout', 15);
            $response = Http::timeout($timeout)->connectTimeout(min(5, $timeout))->accept('application/pdf')->get($url);
        } catch (CarrierException|Throwable $e) {
            Log::warning('Shipping label could not be downloaded', ['tenant' => $shipment->tenant_id, 'shipment' => $shipment->getKey(), 'error' => class_basename($e)]);

            return null;
        }

        return $response->successful() && str_starts_with(ltrim($response->body()), '%PDF') ? $response->body() : null;
    }
}
