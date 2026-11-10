<?php

namespace App\Shipping\Shipments;

use App\Automation\RetailStages;
use App\Models\CarrierAccount;
use App\Models\Lead;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Carriers\ShipmentStatus;
use Illuminate\Support\Collection;

/**
 * Where an OK order stands between "Dikemas & dikirim" and the buyer, read from its newest parcel (never from the
 * stage): one place for the Pipeline's two derived columns (Dikemas / Dikirim) and the Inbox chips.
 *
 * - needs_resi: no parcel yet, the resi failed, waits for balance, or is still being made ("Perlu resi"; held
 *   when something stops it, with the reason).
 * - ready_print: tracking number issued, label not printed yet ("Siap cetak").
 * - printed: printed (or linked from the provider's app), the courier has not collected it.
 * - pickup_failed, in_transit, delivery_failed, done: what the courier says after that.
 */
final class PackingState
{
    public const NEEDS_RESI = 'needs_resi';

    public const READY = 'ready_print';

    public const PRINTED = 'printed';

    public const PICKUP_FAILED = 'pickup_failed';

    public const IN_TRANSIT = 'in_transit';

    public const DELIVERY_FAILED = 'delivery_failed';

    public const DONE = 'done';

    public function __construct(
        public readonly string $phase,
        public readonly ?Shipment $shipment = null,
        public readonly ?string $reason = null,
        public readonly bool $held = false,
    ) {}

    /** Dikemas (packed, not printed) rather than Dikirim. */
    public function packed(): bool
    {
        return in_array($this->phase, [self::NEEDS_RESI, self::READY], true);
    }

    /** The courier's word for a printed parcel: "Menunggu jemput", "Dalam perjalanan", "Sedang diantar", ... */
    public function courierStatus(): ?string
    {
        $status = $this->shipment?->status;

        return match ($this->phase) {
            self::PRINTED => 'Menunggu jemput',
            self::PICKUP_FAILED => 'Gagal dijemput',
            self::IN_TRANSIT => $status === ShipmentStatus::OUT_FOR_DELIVERY ? 'Sedang diantar' : 'Dalam perjalanan',
            self::DELIVERY_FAILED => 'Gagal antar',
            self::DONE => ShipmentStatus::label($status),
            default => null,
        };
    }

    /** Whole days since the label was printed (Dikirim cards). */
    public function daysSincePrinted(): ?int
    {
        $at = $this->shipment?->printed_at ?? ($this->shipment?->manual() ? $this->shipment->linked_at : null);

        return $at !== null ? (int) floor($at->diffInDays(now())) : null;
    }

    /**
     * The Inbox chip: [label, color], or null for parcels on their way or done.
     *
     * @return array{0: string, 1: string}|null
     */
    public function chip(): ?array
    {
        return match ($this->phase) {
            self::NEEDS_RESI => ['Order OK · perlu resi', $this->held ? 'danger' : 'warning'],
            self::READY => ['Order OK · siap cetak', 'info'],
            self::PRINTED => ['Dicetak · menunggu jemput', 'gray'],
            self::PICKUP_FAILED => ['Gagal dijemput', 'danger'],
            self::DELIVERY_FAILED => ['Gagal antar', 'danger'],
            default => null,
        };
    }

    /** True when the chat needs someone for the parcel (Inbox filter "Perlu tindakan pengiriman"). */
    public function needsAction(): bool
    {
        return in_array($this->phase, [self::NEEDS_RESI, self::READY, self::PRINTED, self::PICKUP_FAILED, self::DELIVERY_FAILED], true);
    }

    /** @return array<int, self> by lead id */
    public static function forLeads(Collection $leads): array
    {
        if ($leads->isEmpty()) {
            return [];
        }

        $parcels = Shipment::query()->whereIn('lead_id', $leads->modelKeys())->where('state', '!=', Shipment::CANCELLED)
            ->orderByDesc('id')->get()->unique('lead_id')->keyBy('lead_id');

        return $leads->mapWithKeys(fn (Lead $lead) => [$lead->getKey() => self::of($lead, $parcels->get($lead->getKey()))])->all();
    }

    public static function of(Lead $lead, ?Shipment $shipment): self
    {
        if ($shipment === null) {
            if (filled($lead->tracking_number)) {
                return new self(self::PRINTED);
            }

            return new self(self::NEEDS_RESI, null, ...self::whyNoResi($lead));
        }

        if ($shipment->state === Shipment::FAILED) {
            return new self(self::NEEDS_RESI, $shipment, 'resi gagal dibuat'.(filled($shipment->error) ? ': '.self::short($shipment->error) : ''), true);
        }

        if ($shipment->waitingBalance()) {
            return new self(self::NEEDS_RESI, $shipment, 'saldo '.Carriers::label($shipment->provider).' kurang', true);
        }

        if (in_array($shipment->state, [Shipment::PENDING, Shipment::ORDERED], true) || blank($shipment->awb)) {
            return filled($shipment->error)
                ? new self(self::NEEDS_RESI, $shipment, 'nomor resi belum terbit: '.self::short($shipment->error), true)
                : new self(self::NEEDS_RESI, $shipment, 'menunggu nomor resi', false);
        }

        $status = $shipment->status ?? ShipmentStatus::AWB_CREATED;
        $printed = $shipment->printed_at !== null || $shipment->manual();

        return match (true) {
            in_array($status, ShipmentStatus::FINAL, true), in_array($status, [ShipmentStatus::RETURNING], true) => new self(self::DONE, $shipment),
            $status === ShipmentStatus::DELIVERY_FAILED => new self(self::DELIVERY_FAILED, $shipment, $shipment->status_reason),
            $status === ShipmentStatus::PICKUP_FAILED => new self(self::PICKUP_FAILED, $shipment, $shipment->status_reason),
            ShipmentStatus::rank($status) > ShipmentStatus::rank(ShipmentStatus::AWB_CREATED) => new self(self::IN_TRANSIT, $shipment),
            $printed => new self(self::PRINTED, $shipment),
            default => new self(self::READY, $shipment),
        };
    }

    /**
     * Why a lead has no resi yet: what resi otomatis said, else what is missing on the lead, else nothing.
     *
     * @return array{0: string, 1: bool} reason, held
     */
    private static function whyNoResi(Lead $lead): array
    {
        if ($lead->auto_shipment_state === AutoShipments::SCHEDULED && $lead->auto_shipment_at !== null) {
            return ['resi otomatis dijadwalkan '.$lead->auto_shipment_at->timezone(app(\App\Support\CurrentTenant::class)->get()?->timezone ?: 'Asia/Jakarta')->format('H:i'), false];
        }

        if (in_array($lead->auto_shipment_state, [AutoShipments::SKIPPED, AutoShipments::FAILED, AutoShipments::WAITING_BALANCE], true) && filled($lead->auto_shipment_note)) {
            return [self::short(preg_replace(['/^Resi otomatis (tidak dibuat|gagal)\s*:\s*/i', '/\s*Buat resi manual\.?$/i'], '', (string) $lead->auto_shipment_note)), true];
        }

        if ($lead->provider_location_state === ProviderLocations::MULTIPLE) {
            return ['kelurahan tujuan perlu dipilih', true];
        }

        if ($lead->provider_location_state === ProviderLocations::NONE) {
            return ['lokasi tujuan tidak ketemu di penyedia', true];
        }

        $problems = app(AutoShipments::class)->problems($lead);

        return $problems !== [] ? [implode(', ', $problems), true] : ['belum ada resi', true];
    }

    private static function short(string $text): string
    {
        return mb_strimwidth(rtrim(trim($text), '.'), 0, 160, '…');
    }

    // ---- Settings -------------------------------------------------------------------------------------------

    /**
     * Pipeline "Pisahkan Dikemas dan Dikirim": the agency's choice, else on for a shop with a shipping provider.
     */
    public static function splitEnabled(?Tenant $tenant): bool
    {
        if ($tenant === null || $tenant->industry()->key !== 'retail') {
            return false;
        }

        $chosen = data_get($tenant->pipeline_settings, 'split_shipping');

        return $chosen !== null ? (bool) $chosen : CarrierAccount::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->exists();
    }

    /** The stage shown as two columns ("Dikemas & dikirim"): resi otomatis's trigger stage, else the shop's shipping stage. */
    public static function stageId(): ?int
    {
        return (new RetailStages)->stage(RetailStages::SHIPPING)?->getKey();
    }
}
