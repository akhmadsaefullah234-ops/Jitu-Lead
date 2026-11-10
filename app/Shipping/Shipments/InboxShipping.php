<?php

namespace App\Shipping\Shipments;

use App\Automation\RetailStages;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Shipment;
use App\Shipping\Carriers\ShipmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Inbox's shipping chips, so an OK order whose resi was not made automatically is not lost among the chats:
 *
 * - "Order OK · perlu resi" (with why resi otomatis did not run, and "Buat resi"), "Order OK · siap cetak",
 *   "Dicetak · menunggu jemput", "Gagal dijemput" and "Gagal antar" (red), read from App\Shipping\Shipments\PackingState
 *   for leads in "Dikemas & dikirim" or with a parcel. Parcels on their way or done get none.
 * - "OK/closing": the buyer confirmed the order (activity order_recorded) but the lead is still in an earlier stage.
 *
 * Together they are the filter "Perlu tindakan pengiriman"; such a chat comes first when the buyer wrote after the OK.
 */
final class InboxShipping
{
    /**
     * @return array<int, array{label: string, color: string, reason: ?string, create: bool, ok_at: ?Carbon, location: ?string}> by lead id, only leads with a chip
     */
    public static function chips(Collection $leads): array
    {
        $stageId = PackingState::stageId();
        $leads = $leads->filter()->unique('id')->values();

        if ($leads->isEmpty()) {
            return [];
        }

        $states = PackingState::forLeads($leads);
        $recorded = self::recordedAt($leads->map(fn ($l) => $l->getKey())->all());
        $early = self::earlyStageIds();
        $out = [];

        foreach ($leads as $lead) {
            $state = $states[$lead->getKey()];
            $inStage = $stageId !== null && (int) $lead->stage_id === $stageId;

            if (($inStage || $state->shipment !== null) && ($chip = $state->chip()) !== null && $state->needsAction()) {
                $out[$lead->getKey()] = [
                    'label' => $chip[0], 'color' => $chip[1],
                    'reason' => $state->phase === PackingState::NEEDS_RESI || in_array($state->phase, [PackingState::DELIVERY_FAILED, PackingState::PICKUP_FAILED], true) ? $state->reason : null,
                    'create' => $state->phase === PackingState::NEEDS_RESI && ($state->shipment === null || $state->shipment->state === Shipment::FAILED),
                    'ok_at' => $inStage ? $lead->stage_entered_at : ($recorded[$lead->getKey()] ?? null),
                    'location' => ProviderLocations::chip($lead),
                ];

                continue;
            }

            if (! $inStage && $state->shipment === null && isset($recorded[$lead->getKey()]) && in_array((int) $lead->stage_id, $early, true)) {
                $out[$lead->getKey()] = ['label' => 'OK/closing', 'color' => 'success', 'reason' => 'pembeli sudah konfirmasi pesanan; pindahkan ke Dikemas & dikirim', 'create' => false,
                    'ok_at' => $recorded[$lead->getKey()], 'location' => ProviderLocations::chip($lead)];
            }
        }

        return $out;
    }

    /**
     * Leads (of $visible) a chat filter "Perlu tindakan pengiriman" shows.
     *
     * @return array<int, array> by lead id, as chips()
     */
    public static function needingAction(Builder $visible): array
    {
        $stageId = PackingState::stageId();
        $early = self::earlyStageIds();

        $leads = (clone $visible)->whereHas('conversation')->where(fn (Builder $q) => $q
            ->when($stageId, fn ($q) => $q->orWhere('leads.stage_id', $stageId))
            ->orWhereIn('leads.id', Shipment::query()->whereNull('final_at')->whereIn('status', [ShipmentStatus::DELIVERY_FAILED, ShipmentStatus::PICKUP_FAILED])->select('lead_id'))
            ->orWhere(fn ($q) => $q->whereIn('leads.stage_id', $early ?: [0])->whereIn('leads.id', LeadActivity::query()->where('type', 'order_recorded')->select('lead_id'))))
            ->limit(500)->get();

        return self::chips($leads);
    }

    /** @return array<int, Carbon> when each lead's order was recorded (newest) */
    private static function recordedAt(array $ids): array
    {
        return LeadActivity::query()->whereIn('lead_id', $ids)->where('type', 'order_recorded')->groupBy('lead_id')
            ->selectRaw('lead_id, max(created_at) as at')->pluck('at', 'lead_id')->map(fn ($at) => Carbon::parse($at))->all();
    }

    /** Stages before "Dikemas & dikirim" (inquiry, order, payment) where a confirmed order may still sit. */
    private static function earlyStageIds(): array
    {
        $stages = new RetailStages;

        return array_values(array_filter(array_map(fn (string $key) => $stages->stage($key)?->getKey(), [RetailStages::INQUIRY, RetailStages::ORDER, RetailStages::PAYMENT])));
    }
}
