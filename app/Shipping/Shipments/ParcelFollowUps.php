<?php

namespace App\Shipping\Shipments;

use App\Actions\SendWhatsAppMessage;
use App\Billing\PlanLimits;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Enums\StageType;
use App\Models\Lead;
use App\Models\ParcelFollowUp;
use App\Models\Shipment;
use App\Models\ShipmentNotice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Shipping\Carriers\ShipmentStatus;
use App\Support\CurrentTenant;
use App\WhatsApp\RouteSelector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Follow-up harian paket gagal antar (Pengaturan > Otomatisasi tahap, ParcelFollowUpSettings): once a day at the
 * agency's send hour, a buyer whose parcel the courier could not deliver gets one of the agency's texts in turn, until
 * one of these stops it (a note on the lead each time):
 *
 * - the buyer wrote after the failure (any message we received, also on a gateway phone);
 * - the lead was moved to a lost stage (Batal): a person decides that, never a guess from the chat text;
 * - the parcel was cancelled, delivered or came back to the shop (returned);
 * - a person pressed "Hentikan follow-up paket";
 * - the limit of messages for the parcel was reached.
 *
 * A reply or the limit also tells the lead's owner (bell) and puts "Perlu tindakan" on the chat until someone answers
 * from the Inbox. A new courier status after a failure (out for delivery, then failed again) starts a new cycle on the
 * same row, so the limit counts every message of the parcel. "returning" takes part only with its own switch.
 *
 * The first message goes the agency's day after the courier status message (never twice on one day, at least
 * MIN_GAP_HOURS apart); one try per parcel per day (last_attempt_on), so a second scheduler run sends nothing.
 * Messages take the lead's usual route (RouteSelector): no number, or only a paid template left (official API with
 * the free window closed), leaves a note instead. A read-only subscription sends nothing.
 */
class ParcelFollowUps
{
    public const ORIGIN = 'shipping';

    public function __construct(private CurrentTenant $current, private RouteSelector $routes, private SendWhatsAppMessage $send) {}

    /** @return list<string> the courier statuses that are followed up under $settings */
    public static function targets(ParcelFollowUpSettings $settings): array
    {
        return $settings->returning ? [ShipmentStatus::DELIVERY_FAILED, ShipmentStatus::RETURNING] : [ShipmentStatus::DELIVERY_FAILED];
    }

    // ---- Courier status (App\Shipping\Shipments\StatusNotices) ---------------------------------------------------

    /** A parcel moved to $status at $at. Runs inside the tenant; kept even while the follow-up is off. */
    public function statusChanged(Shipment $shipment, string $status, Carbon $at): void
    {
        if (in_array($status, [ShipmentStatus::DELIVERY_FAILED, ShipmentStatus::RETURNING], true)) {
            $this->startCycle($shipment, $status, $at);

            return;
        }

        if (in_array($status, ShipmentStatus::FINAL, true)) {
            $row = ParcelFollowUp::query()->where('shipment_id', $shipment->getKey())->first();

            if ($row !== null) {
                $this->stop($row, self::finalReason($status));
                ParcelFollowUp::query()->whereKey($row->getKey())->update(['attention_at' => null, 'attention_reason' => null]);
            }
        }
    }

    private function startCycle(Shipment $shipment, string $status, Carbon $at): void
    {
        $row = ParcelFollowUp::query()->firstOrNew(['shipment_id' => $shipment->getKey()], ['lead_id' => $shipment->lead_id, 'cycle' => 0, 'sent_count' => 0]);
        $settings = ParcelFollowUpSettings::of($this->current->get());
        $full = $settings->limit > 0 && $row->sent_count >= $settings->limit;

        $row->forceFill([
            'lead_id' => $shipment->lead_id, 'status' => $status, 'failed_at' => $at->copy()->setTimezone(config('app.timezone')), 'cycle' => $row->cycle + 1,
            'state' => $full ? ParcelFollowUp::STOPPED : ParcelFollowUp::ACTIVE,
            'stop_reason' => $full ? $this->limitReason($settings) : null, 'stopped_at' => $full ? ($row->stopped_at ?? now()) : null,
            'attention_at' => null, 'attention_reason' => null,
        ])->save();
    }

    // ---- The scheduled run --------------------------------------------------------------------------------------

    /** @return array{sent: int, stopped: int, blocked: int} */
    public function run(Tenant $tenant, ?Carbon $now = null): array
    {
        return $this->current->run($tenant, function () use ($tenant, $now) {
            $now ??= now();
            $count = ['sent' => 0, 'stopped' => 0, 'blocked' => 0];
            $settings = ParcelFollowUpSettings::of($tenant);

            if (! $settings->enabled || ! ParcelFollowUpSettings::appliesTo($tenant) || PlanLimits::for($tenant)->readOnly()) {
                return $count;
            }

            $this->discover($settings);

            $rows = ParcelFollowUp::query()->where('state', ParcelFollowUp::ACTIVE)->with(['shipment', 'lead.stage'])->orderBy('id')->get();

            foreach ($rows as $row) {
                try {
                    if ($reason = $this->stopReason($row)) {
                        $this->stop($row, $reason[0], $reason[1]) && $count['stopped']++;

                        continue;
                    }

                    if (! $this->due($row, $settings, $now)) {
                        continue;
                    }

                    if ($settings->limit > 0 && $row->sent_count >= $settings->limit) {
                        $this->stop($row, $this->limitReason($settings), true) && $count['stopped']++;

                        continue;
                    }

                    $result = $this->deliver($row, $settings, $now);
                    $result === true ? $count['sent']++ : ($result === false ? $count['blocked']++ : null);
                } catch (Throwable $e) {
                    report($e);
                }
            }

            return $count;
        });
    }

    /** Parcels failed before this code tracked cycles (or while tracking was down): a row, when recent enough. */
    private function discover(ParcelFollowUpSettings $settings): void
    {
        $cutoff = $settings->cutoff();

        Shipment::query()->whereIn('status', self::targets($settings))->whereNull('final_at')->where('state', '!=', Shipment::CANCELLED)
            ->whereNotIn('id', ParcelFollowUp::query()->select('shipment_id'))->get()
            ->each(function (Shipment $shipment) use ($cutoff) {
                $at = self::failedAt($shipment);

                if ($at->gte($cutoff)) {
                    ParcelFollowUp::query()->firstOrCreate(['shipment_id' => $shipment->getKey()], [
                        'lead_id' => $shipment->lead_id, 'status' => $shipment->status, 'failed_at' => $at, 'cycle' => 1, 'sent_count' => 0, 'state' => ParcelFollowUp::ACTIVE,
                    ]);
                }
            });
    }

    /** When the parcel reached its current status, from its history, else the courier message, else its last change. */
    public static function failedAt(Shipment $shipment): Carbon
    {
        $event = collect((array) $shipment->events)->filter(fn ($e) => ($e['status'] ?? null) === $shipment->status && filled($e['at'] ?? null))->last();

        if ($event !== null) {
            try {
                return Carbon::parse($event['at'])->setTimezone(config('app.timezone'));
            } catch (Throwable) {
            }
        }

        $notice = ShipmentNotice::query()->where('shipment_id', $shipment->getKey())->where('status', $shipment->status)->value('event_at');

        return $notice !== null ? Carbon::parse($notice) : ($shipment->tracked_at ?? $shipment->updated_at ?? now())->copy();
    }

    /** @return array{0: string, 1: bool}|null why the follow-up ends now, and whether a person must act */
    private function stopReason(ParcelFollowUp $row): ?array
    {
        $shipment = $row->shipment;
        $lead = $row->lead;

        if ($shipment === null || $shipment->trashed() || $shipment->state === Shipment::CANCELLED || $shipment->status === ShipmentStatus::CANCELLED) {
            return ['kiriman dibatalkan', false];
        }

        if (in_array($shipment->status, ShipmentStatus::FINAL, true)) {
            return [self::finalReason($shipment->status), false];
        }

        if ($lead === null || $lead->trashed() || $lead->stage?->type === StageType::Lost) {
            return ['lead dipindah ke '.($lead?->stage?->name ?? 'Batal'), false];
        }

        $replied = WaMessage::query()->where('direction', 'in')->where('sent_at', '>', $row->failed_at)
            ->whereIn('conversation_id', WaConversation::query()->where('lead_id', $lead->getKey())->select('id'))->exists();

        return $replied ? ['pembeli membalas', true] : null;
    }

    private static function finalReason(string $status): string
    {
        return match ($status) {
            ShipmentStatus::DELIVERED => 'paket diterima pembeli',
            ShipmentStatus::RETURNED => 'paket sudah kembali ke toko',
            default => 'kiriman dibatalkan',
        };
    }

    private function limitReason(ParcelFollowUpSettings $settings): string
    {
        return "batas {$settings->limit} pesan tercapai";
    }

    /** At or after the send hour on a send day, not tried today, a day after the last message about the parcel. */
    public function due(ParcelFollowUp $row, ParcelFollowUpSettings $settings, Carbon $now): bool
    {
        $tz = $this->timezone();
        $local = $now->copy()->timezone($tz);

        if (! in_array($local->dayOfWeekIso, $settings->days, true) || $local->hour < $settings->hour || $local->hour >= ParcelFollowUpSettings::SEND_UNTIL) {
            return false;
        }

        if ($row->last_attempt_on === $local->toDateString() || $row->failed_at->lt($settings->cutoff())) {
            return false;
        }

        if (! in_array($row->status, self::targets($settings), true) || $row->shipment?->status !== $row->status) {
            return false;
        }

        $anchor = collect([$row->failed_at, $row->last_sent_at, $this->lastParcelMessageAt($row->lead)])->filter()->max();

        return $anchor->copy()->timezone($tz)->toDateString() < $local->toDateString() && $anchor->lte($now->copy()->subHours(ParcelFollowUpSettings::MIN_GAP_HOURS));
    }

    /** The last courier status or follow-up message sent to the buyer. */
    private function lastParcelMessageAt(?Lead $lead): ?Carbon
    {
        if ($lead === null) {
            return null;
        }

        $at = WaMessage::query()->where('direction', 'out')->where('origin', self::ORIGIN)->where('status', '!=', MessageStatus::Failed)
            ->whereIn('conversation_id', WaConversation::query()->where('lead_id', $lead->getKey())->select('id'))->max('sent_at');

        return $at !== null ? Carbon::parse($at) : null;
    }

    /** @return bool|null true sent, false blocked (a note says why), null not tried (another run took it) */
    private function deliver(ParcelFollowUp $row, ParcelFollowUpSettings $settings, Carbon $now): ?bool
    {
        $today = $now->copy()->timezone($this->timezone())->toDateString();
        $claimed = ParcelFollowUp::query()->whereKey($row->getKey())->where('state', ParcelFollowUp::ACTIVE)
            ->where(fn ($q) => $q->whereNull('last_attempt_on')->orWhere('last_attempt_on', '!=', $today))
            ->update(['last_attempt_on' => $today, 'updated_at' => now()]);

        if ($claimed === 0) {
            return null;
        }

        $row->refresh();
        $shipment = $row->shipment;
        $lead = $row->lead;
        $texts = $settings->textsFor($row->status);
        $variant = $row->sent_count % count($texts);
        $number = $row->sent_count + 1;
        $label = $row->status === ShipmentStatus::RETURNING ? 'paket diretur' : 'paket gagal antar';

        $conversation = filled($lead->phone) ? WaConversation::query()->firstOrCreate(['lead_id' => $lead->getKey()], ['phone' => $lead->phone]) : null;
        $decision = $conversation ? $this->routes->decide($conversation) : null;
        $actor = $this->actor($lead);

        if ($actor === null || $decision === null || ! $decision->canSend() || $decision->isPaid()) {
            $why = $decision?->isPaid() ? 'jendela chat gratis sudah tutup dan hanya template berbayar yang bisa dikirim' : ($decision?->note ?: 'lead belum punya nomor WhatsApp');
            $lead->activities()->create(['type' => 'note', 'body' => "Follow-up {$label} hari ini tidak terkirim: {$why}."]);

            return false;
        }

        $text = app(StatusNotices::class)->render($texts[$variant], $shipment, $lead);

        try {
            $message = $this->send->from(self::ORIGIN)($conversation, $actor, $text);
        } catch (Throwable $e) {
            $lead->activities()->create(['type' => 'note', 'body' => "Follow-up {$label} hari ini tidak terkirim: ".mb_substr($e->getMessage(), 0, 200)]);

            return false;
        }

        if ($message->status === MessageStatus::Failed) {
            $lead->activities()->create(['type' => 'note', 'body' => "Follow-up {$label} hari ini gagal terkirim: ".mb_substr((string) $message->error, 0, 200)]);

            return false;
        }

        ParcelFollowUp::query()->whereKey($row->getKey())->update(['sent_count' => $number, 'last_sent_at' => now(), 'updated_at' => now()]);
        $limit = $settings->limit > 0 ? " dari {$settings->limit}" : '';
        $lead->activities()->create(['type' => 'note', 'body' => "Follow-up {$label} ke-{$number}{$limit} terkirim (teks ".($variant + 1).', resi '.($shipment->awb ?: '-').').']);

        return true;
    }

    // ---- Stopping, "Perlu tindakan" -------------------------------------------------------------------------------

    /** Ends the follow-up once (true when this call ended it), with a note; $attention also tells the owner. */
    public function stop(ParcelFollowUp $row, string $reason, bool $attention = false): bool
    {
        $stopped = ParcelFollowUp::query()->whereKey($row->getKey())->where('state', ParcelFollowUp::ACTIVE)->update(array_filter([
            'state' => ParcelFollowUp::STOPPED, 'stop_reason' => mb_substr($reason, 0, 120), 'stopped_at' => now(), 'updated_at' => now(),
            'attention_at' => $attention ? now() : null, 'attention_reason' => $attention ? mb_substr($reason, 0, 120) : null,
        ], fn ($v) => $v !== null));

        if ($stopped === 0) {
            return false;
        }

        $row->refresh();
        $lead = $row->lead;
        $lead?->activities()->create(['type' => 'note', 'body' => 'Follow-up paket gagal antar berhenti: '.$reason.' (terkirim '.$row->sent_count.' pesan).']);

        if ($attention && $row->shipment && $lead) {
            app(StatusNotices::class)->alertStaff($row->shipment, 'Follow-up paket berhenti, perlu tindakan',
                ucfirst($reason).'. Hubungi pembeli lalu putuskan: kirim ulang, ubah alamat, atau pindahkan ke Batal.');
        }

        return true;
    }

    /** The open follow-up of the lead's newest parcel, if any. */
    public static function activeFor(?Lead $lead): ?ParcelFollowUp
    {
        $shipment = $lead ? Shipment::latestFor($lead) : null;

        return $shipment ? ParcelFollowUp::query()->where('shipment_id', $shipment->getKey())->where('state', ParcelFollowUp::ACTIVE)->first() : null;
    }

    /** "Hentikan follow-up paket": a person ends it for this cycle (a new failure starts again). */
    public function stopByHand(Lead $lead, User $user): bool
    {
        $row = self::activeFor($lead);

        return $row !== null && $this->stop($row, 'dihentikan oleh '.$user->name);
    }

    /** Someone answered the buyer from the Inbox: the "Perlu tindakan" mark goes. */
    public static function handled(?Lead $lead): void
    {
        if ($lead !== null) {
            ParcelFollowUp::query()->where('lead_id', $lead->getKey())->whereNotNull('attention_at')->update(['attention_at' => null, 'attention_reason' => null, 'updated_at' => now()]);
        }
    }

    /**
     * @param  iterable<int>  $leadIds
     * @return array<int, string> lead id => why it needs someone
     */
    public static function attention(iterable $leadIds): array
    {
        $ids = collect($leadIds)->filter()->unique()->values()->all();

        return $ids === [] ? [] : ParcelFollowUp::query()->whereIn('lead_id', $ids)->whereNotNull('attention_at')->orderBy('attention_at')
            ->pluck('attention_reason', 'lead_id')->map(fn ($r) => (string) $r)->all();
    }

    /**
     * Failed parcels left out on switching on (older than BACKLOG_DAYS before that day), for a person to handle.
     *
     * @return Collection<int, array{shipment: Shipment, failed_at: Carbon}>
     */
    public static function backlog(ParcelFollowUpSettings $settings): Collection
    {
        $cutoff = $settings->cutoff();
        $rows = ParcelFollowUp::query()->pluck('failed_at', 'shipment_id');

        return Shipment::query()->whereIn('status', self::targets($settings))->whereNull('final_at')->where('state', '!=', Shipment::CANCELLED)
            ->with('lead:id,name,stage_id')->orderBy('id')->get()
            ->map(fn (Shipment $s) => ['shipment' => $s, 'failed_at' => isset($rows[$s->getKey()]) ? Carbon::parse($rows[$s->getKey()]) : self::failedAt($s)])
            ->filter(fn (array $r) => $r['failed_at']->lt($cutoff))->values();
    }

    private function actor(Lead $lead): ?User
    {
        return $lead->owner ?? $this->current->get()?->activeUsers()->wherePivot('role', Role::Admin->value)->first();
    }

    private function timezone(): string
    {
        return $this->current->get()?->timezone ?: 'Asia/Jakarta';
    }
}
