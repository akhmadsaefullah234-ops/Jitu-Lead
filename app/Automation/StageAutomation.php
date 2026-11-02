<?php

namespace App\Automation;

use App\Actions\MoveLeadToStage;
use App\Actions\SendWhatsAppMessage;
use App\Billing\PlanLimits;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Enums\StageType;
use App\Mail\PaymentProofReceived;
use App\Models\FollowUpLog;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Push\Alerts;
use App\Shipping\Regions;
use App\Support\CurrentTenant;
use App\WhatsApp\RouteSelector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Moves a shop's leads through the retail pipeline by themselves (Pengaturan > Otomatisasi tahap): order details
 * complete, order confirmed (transfer or COD), payment proof sent, tracking number entered, parcel delivered,
 * buyer silent, payment never made, and a cancelled buyer writing again. Every move goes through
 * MoveLeadToStage, so it is written to the lead's activity ("Pindah otomatis ...: reason") and the new stage's
 * follow-ups run as usual. A lead in a won stage (Selesai) is never moved automatically. Agents can still move
 * leads by hand at any time. Must run inside the tenant.
 */
class StageAutomation
{
    public const SHIPPED_MESSAGE = "Halo {nama}, pesanan Anda sudah kami kirim.\nKurir: {kurir}\nNomor resi: {resi}\nLacak paket: {lacak}\nTerima kasih sudah berbelanja!";

    public const JAVA = ['Banten', 'DKI Jakarta', 'Jawa Barat', 'Jawa Tengah', 'DI Yogyakarta', 'Jawa Timur'];

    private ?RetailStages $stages = null;

    public function __construct(private CurrentTenant $current, private MoveLeadToStage $move, private RouteSelector $routes) {}

    public function applies(): bool
    {
        return StageAutomationSettings::appliesTo($this->current->get());
    }

    public function on(string $rule): bool
    {
        return $this->applies() && StageAutomationSettings::of($this->current->get())->enabled($rule);
    }

    public function stages(): RetailStages
    {
        return $this->stages ??= new RetailStages;
    }

    // ---- Rules 1 and 2: the AI (or an agent) records an order ------------------------------------------------

    /** Rule 1: the order details are complete but the buyer has not confirmed yet. */
    public function orderDrafted(Lead $lead): ?string
    {
        return $this->on('order_draft') ? $this->forward($lead, RetailStages::ORDER, 'format order lengkap terdeteksi') : null;
    }

    /** Rule 2: the buyer confirmed. Transfer waits for payment; cash on delivery goes straight to packing. */
    public function orderConfirmed(Lead $lead, string $payment): ?string
    {
        if (! $this->on('order_confirmed')) {
            return null;
        }

        return $payment === 'cod'
            ? $this->forward($lead, RetailStages::SHIPPING, 'pembeli konfirmasi pesanan, bayar di tempat (COD)')
            : $this->forward($lead, RetailStages::PAYMENT, 'pembeli konfirmasi pesanan, bayar transfer');
    }

    // ---- Rules 3 and 7: a message from the buyer ------------------------------------------------------------

    public function inbound(Lead $lead, WaMessage $message): void
    {
        if (! $this->applies()) {
            return;
        }

        $lead->loadMissing('stage');

        if ($message->type === 'image' && $this->on('payment_proof') && $this->stages()->is($lead->stage, RetailStages::PAYMENT) && $lead->paid_at === null) {
            $this->paymentProof($lead);

            return;
        }

        // Rule 7: never from a won stage; only a cancelled (lost) buyer comes back.
        if ($this->on('reopen') && $lead->stage?->type === StageType::Lost) {
            $this->moveTo($lead, RetailStages::INQUIRY, 'pembeli yang batal mengirim chat baru');
        }
    }

    /** Rule 3: flags the lead for a person to check; nothing here (or the AI) says the payment was received. */
    private function paymentProof(Lead $lead): void
    {
        $first = $lead->payment_proof_at === null;
        $lead->forceFill(['payment_proof_at' => now()])->save();
        $lead->activities()->create(['type' => 'payment_proof', 'body' => 'Bukti transfer masuk, perlu dicek']);

        if (! $first) {
            return;
        }

        try {
            app(Alerts::class)->paymentProof($lead);
        } catch (Throwable $e) {
            report($e);
        }

        $tenant = $this->current->get();

        foreach ($this->staff($tenant, $lead) as $person) {
            try {
                Mail::to($person->email)->send(new PaymentProofReceived($tenant, $lead));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** The "Pembayaran diterima" button: an agent checked the transfer. */
    public function paymentReceived(Lead $lead, User $actor): void
    {
        DB::transaction(function () use ($lead, $actor) {
            $lead->forceFill(['paid_at' => now(), 'payment_proof_at' => null])->save();
            $lead->activities()->create(['user_id' => $actor->getKey(), 'type' => 'payment_received', 'body' => 'Pembayaran dikonfirmasi diterima oleh '.$actor->name]);

            $target = $this->stages()->stage(RetailStages::SHIPPING);

            if ($target !== null && $this->before($lead->stage, $target)) {
                ($this->move)($lead, $target, $actor);
            }
        });
    }

    public function canConfirmPayment(?Lead $lead): bool
    {
        return $lead !== null && $this->applies() && $lead->paid_at === null
            && ($lead->payment_proof_at !== null || $this->stages()->is($lead->stage, RetailStages::PAYMENT));
    }

    // ---- Rule 4: tracking number ---------------------------------------------------------------------------

    /**
     * Keeps the courier and tracking number and, when the rule is on, sends them to the buyer on WhatsApp and moves
     * the lead to "Dikemas & dikirim". The lead's fields are saved even when the message cannot go out.
     *
     * @return string what happened, for the person who entered it
     */
    public function shipped(Lead $lead, ?string $courier, string $trackingNumber, ?User $actor = null): string
    {
        $trackingNumber = strtoupper(preg_replace('/\s+/', '', $trackingNumber));
        $lead->forceFill(array_filter(['courier' => filled($courier) ? trim($courier) : null], fn ($v) => $v !== null) + [
            'tracking_number' => $trackingNumber, 'shipped_at' => now(),
        ])->save();

        if (! $this->on('tracking')) {
            return 'Nomor resi disimpan.';
        }

        $sent = $this->sendTracking($lead, $actor);
        $this->forward($lead, RetailStages::SHIPPING, 'nomor resi diisi');

        return $sent;
    }

    private function sendTracking(Lead $lead, ?User $actor): string
    {
        $actor ??= $this->staffActor($lead);
        $conversation = filled($lead->phone) ? WaConversation::query()->firstOrCreate(['lead_id' => $lead->getKey()], ['phone' => $lead->phone]) : null;
        $decision = $conversation ? $this->routes->decide($conversation) : null;

        if ($actor === null || $decision === null || ! $decision->canSend() || $decision->isPaid()) {
            $why = $decision?->isPaid() ? 'jendela chat gratis sudah tutup' : ($decision?->note ?: 'lead belum punya nomor WhatsApp');
            $lead->activities()->create(['type' => 'tracking_not_sent', 'body' => "Nomor resi {$lead->tracking_number} belum terkirim ke pembeli: {$why}. Kirim manual dari Inbox."]);

            return 'Nomor resi disimpan, tapi belum terkirim ke pembeli: '.$why.'.';
        }

        $text = strtr(self::SHIPPED_MESSAGE, [
            '{nama}' => $lead->name, '{kurir}' => $lead->courier ?: '-', '{resi}' => $lead->tracking_number, '{lacak}' => self::trackingUrl($lead->courier, $lead->tracking_number),
        ]);

        try {
            $message = app(SendWhatsAppMessage::class)($conversation, $actor, $text);
        } catch (Throwable $e) {
            $lead->activities()->create(['type' => 'tracking_not_sent', 'body' => "Nomor resi {$lead->tracking_number} gagal terkirim ke pembeli: {$e->getMessage()}"]);

            return 'Nomor resi disimpan, tapi gagal terkirim: '.$e->getMessage();
        }

        if ($message->status === MessageStatus::Failed) {
            return 'Nomor resi disimpan, tapi gagal terkirim: '.$message->error;
        }

        return 'Nomor resi disimpan dan dikirim ke pembeli lewat WhatsApp.';
    }

    public static function trackingUrl(?string $courier, string $trackingNumber): string
    {
        $urls = (array) config('shipping.tracking_urls');
        $brand = mb_strtolower(strtok(trim((string) $courier), " \t") ?: '');
        $url = $urls[$brand] ?? $urls['default'] ?? 'https://cekresi.com/?noresi={resi}';

        return str_replace('{resi}', rawurlencode($trackingNumber), $url);
    }

    // ---- Rules 5 and 6: the scheduled run ------------------------------------------------------------------

    /** @return array{done: int, no_reply: int, no_payment: int} */
    public function runScheduled(Tenant $tenant): array
    {
        return $this->current->run($tenant, function () {
            $this->stages = null;
            $count = ['done' => 0, 'no_reply' => 0, 'no_payment' => 0];

            if (! $this->applies() || PlanLimits::for($this->current->get())->readOnly()) {
                return $count;
            }

            $settings = StageAutomationSettings::of($this->current->get());

            if ($settings->enabled('delivered') && ($stage = $this->stages()->stage(RetailStages::SHIPPING))) {
                $java = $settings->days('delivered', 'days_java');
                $other = $settings->days('delivered', 'days_other');

                foreach (Lead::query()->where('stage_id', $stage->getKey())->whereNotNull('shipped_at')->where('shipped_at', '<=', now()->subDays(min($java, $other)))->get() as $lead) {
                    $days = $this->toJava($lead) ? $java : $other;

                    if ($lead->shipped_at->lte(now()->subDays($days)) && $this->moveTo($lead, RetailStages::DONE, "{$days} hari setelah nomor resi dikirim", $this->doneDetails($lead))) {
                        $count['done']++;
                    }
                }
            }

            if ($settings->enabled('no_reply') && ($stage = $this->stages()->stage(RetailStages::INQUIRY))) {
                $days = $settings->days('no_reply');

                foreach (Lead::query()->where('stage_id', $stage->getKey())->with('conversation')->get() as $lead) {
                    $conversation = $lead->conversation;
                    $lastIn = $conversation?->last_inbound_at;
                    $anchor = $lastIn !== null && $lastIn->gt($lead->stageEnteredAt()) ? $lastIn : $lead->stageEnteredAt();
                    // A buyer whose last message nobody answered yet is waiting on the shop, not silent.
                    $waiting = $conversation !== null && $conversation->messages()->reorder()->latest('id')->value('direction') === 'in';

                    if (! $waiting && $anchor->lte(now()->subDays($days))
                        && $this->moveTo($lead, RetailStages::CANCELLED, "tidak ada balasan pembeli {$days} hari", $this->lostDetails('Tidak ada respons'))) {
                        $count['no_reply']++;
                    }
                }
            }

            if ($settings->enabled('no_payment') && ($stage = $this->stages()->stage(RetailStages::PAYMENT))) {
                $days = $settings->days('no_payment');

                foreach (Lead::query()->where('stage_id', $stage->getKey())->whereNull('payment_proof_at')->whereNull('paid_at')->get() as $lead) {
                    $followUp = FollowUpLog::query()->where('lead_id', $lead->getKey())->where('stage_id', $stage->getKey())->where('status', 'sent')->max('created_at');
                    $anchor = $followUp !== null && now()->parse($followUp)->gt($lead->stageEnteredAt()) ? now()->parse($followUp) : $lead->stageEnteredAt();

                    if ($anchor->lte(now()->subDays($days))
                        && $this->moveTo($lead, RetailStages::CANCELLED, "belum ada pembayaran {$days} hari".($followUp !== null ? ' setelah follow-up terakhir' : ''), $this->lostDetails('Batal setelah order'))) {
                        $count['no_payment']++;
                    }
                }
            }

            return $count;
        });
    }

    public function toJava(Lead $lead): bool
    {
        $province = $lead->shipping_province ?: self::provinceIn((string) $lead->shipping_address);

        // An address the app cannot place gets the longer wait.
        return $province !== null && in_array($province, self::JAVA, true);
    }

    /** The province written in an address: its name or short name, else that of a known city in it. */
    public static function provinceIn(string $address): ?string
    {
        $parts = array_reverse(array_map('trim', preg_split('/[,\n]+/', $address)));

        foreach ($parts as $part) {
            $part = trim(preg_replace('/\s*\d{5}\s*$/', '', $part));

            if ($province = Regions::province($part) ?? Regions::provinceOfCity($part)) {
                return $province;
            }
        }

        return null;
    }

    private function doneDetails(Lead $lead): array
    {
        $items = collect((array) $lead->order_items);
        $value = (int) ($lead->deal_value ?: ($items->sum(fn ($i) => (int) ($i['price'] ?? 0) * (int) ($i['quantity'] ?? 1)) + (int) $lead->shipping_cost));

        return [
            'unit' => $lead->unit ?: (trim(($lead->property?->name ?? 'Pesanan').' '.$lead->variant).($lead->quantity ? ' x'.$lead->quantity : '')),
            'deal_value' => max(1, $value),
        ];
    }

    private function lostDetails(string $reason): array
    {
        return ['lost_reason_id' => LostReason::query()->whereRaw('lower(label) = ?', [mb_strtolower($reason)])->value('id')
            ?? LostReason::query()->create(['label' => $reason])->getKey()];
    }

    // ---- Moving ------------------------------------------------------------------------------------------

    /** Moves only ahead in the pipeline: a lead already further along (or closed) stays put. */
    private function forward(Lead $lead, string $key, string $reason): ?string
    {
        $target = $this->stages()->stage($key);
        $lead->loadMissing('stage');

        if ($target === null || ! $this->before($lead->stage, $target)) {
            return null;
        }

        return $this->moveTo($lead, $key, $reason) ? $target->name : null;
    }

    private function before(?Stage $current, Stage $target): bool
    {
        return $current === null || ($current->isOpen() && ! $current->is($target) && ($target->type !== StageType::Open || $current->position < $target->position));
    }

    private function moveTo(Lead $lead, string $key, string $reason, array $details = []): bool
    {
        $target = $this->stages()->stage($key);
        $lead->loadMissing('stage');

        if ($target === null || $target->is($lead->stage) || $lead->stage?->type === StageType::Won) {
            return false;
        }

        try {
            ($this->move)($lead, $target, $this->staffActor($lead), $details, $reason);
        } catch (ValidationException $e) {
            report($e);

            return false;
        }

        $lead->setRelation('stage', $target);

        return true;
    }

    private function staffActor(Lead $lead): ?User
    {
        return $lead->owner ?? $this->current->get()?->activeUsers()->wherePivot('role', Role::Admin->value)->first();
    }

    /** The lead's owner and the agency's admins. */
    private function staff(Tenant $tenant, Lead $lead)
    {
        return $tenant->activeUsers()->get()->filter(fn (User $u) => $u->getKey() === $lead->owner_id || $u->pivot->role === Role::Admin)->values();
    }
}
