<?php

namespace App\Shipping\Shipments;

use App\Automation\RetailStages;
use App\Models\LeadActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The Inbox's one shipping mark, so an OK order is not lost among the chats: a small green "OK" on the chat card
 * for a closing nobody has handled yet, and the filter "Closing (N)". A lead counts while
 *
 * (a) it sits in "Dikemas & dikirim" without an active resi, or with a resi issued but not printed yet
 *     (App\Shipping\Shipments\PackingState needs_resi / ready_print); or
 * (b) the buyer confirmed the order (activity order_recorded) but the lead is still in an earlier stage.
 *
 * It stops counting once the label is printed (printed_at) or the lead leaves that stage. Everything after that
 * (waiting for pickup, failed pickup or delivery, the Everpro location) lives in the Pengiriman menu and the
 * Pipeline, not in the Inbox.
 */
final class InboxShipping
{
    public const TOOLTIP = 'Order OK, perlu ditangani di menu Pengiriman';

    /**
     * @return array<int, array{label: string, reason: ?string}> by lead id, only unhandled closings: the line shown in
     *                                                           the lead's details ("OK · belum ada resi" / "OK · resi belum dicetak") and why resi otomatis did not run
     */
    public static function closings(Collection $leads): array
    {
        $stageId = PackingState::stageId();
        $leads = $leads->filter()->unique('id')->values();

        if ($leads->isEmpty()) {
            return [];
        }

        $states = PackingState::forLeads($leads);
        $recorded = self::recorded($leads->map(fn ($l) => $l->getKey())->all());
        $early = self::earlyStageIds();
        $out = [];

        foreach ($leads as $lead) {
            $state = $states[$lead->getKey()];

            if ($stageId !== null && (int) $lead->stage_id === $stageId) {
                if ($state->phase === PackingState::NEEDS_RESI) {
                    $out[$lead->getKey()] = ['label' => 'OK · belum ada resi', 'reason' => $state->reason];
                } elseif ($state->phase === PackingState::READY) {
                    $out[$lead->getKey()] = ['label' => 'OK · resi belum dicetak', 'reason' => null];
                }

                continue;
            }

            if ($state->shipment === null && blank($lead->tracking_number) && isset($recorded[$lead->getKey()]) && in_array((int) $lead->stage_id, $early, true)) {
                $out[$lead->getKey()] = ['label' => 'OK · belum ada resi', 'reason' => 'pembeli sudah konfirmasi pesanan; pindahkan ke Dikemas & dikirim'];
            }
        }

        return $out;
    }

    /**
     * The unhandled closings among the chats $visible may see (also beyond the newest 100): the filter "Closing".
     *
     * @return array<int, array{label: string, reason: ?string}> by lead id, as closings()
     */
    public static function waiting(Builder $visible): array
    {
        $stageId = PackingState::stageId();
        $early = self::earlyStageIds();

        $leads = (clone $visible)->whereHas('conversation')->where(fn (Builder $q) => $q
            ->when($stageId, fn ($q) => $q->orWhere('leads.stage_id', $stageId))
            ->orWhere(fn ($q) => $q->whereIn('leads.stage_id', $early ?: [0])->whereIn('leads.id', LeadActivity::query()->where('type', 'order_recorded')->select('lead_id'))))
            ->limit(500)->get();

        return self::closings($leads);
    }

    /** @return array<int, true> leads with a recorded order */
    private static function recorded(array $ids): array
    {
        return LeadActivity::query()->whereIn('lead_id', $ids)->where('type', 'order_recorded')->distinct()->pluck('lead_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /** Stages before "Dikemas & dikirim" (inquiry, order, payment) where a confirmed order may still sit. */
    private static function earlyStageIds(): array
    {
        $stages = new RetailStages;

        return array_values(array_filter(array_map(fn (string $key) => $stages->stage($key)?->getKey(), [RetailStages::INQUIRY, RetailStages::ORDER, RetailStages::PAYMENT])));
    }
}
