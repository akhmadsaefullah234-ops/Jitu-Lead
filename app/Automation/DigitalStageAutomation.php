<?php

namespace App\Automation;

use App\Actions\MoveLeadToStage;
use App\Actions\SendWhatsAppMessage;
use App\Billing\PlanLimits;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Enums\StageType;
use App\Mail\PaymentProofReceived;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Push\Alerts;
use App\Support\CurrentTenant;
use App\WhatsApp\RouteSelector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Moves a digital-product agency's leads through its pipeline (Pengaturan > Otomatisasi tahap): a confirmed purchase
 * waits for payment, a picture sent while waiting is flagged for a person, an unpaid purchase falls to "Tidak jadi
 * bayar" after N hours, and a buyer who did not pay writing again starts over. Payment is only ever confirmed by a
 * person ("Pembayaran diterima"), later by the payment gateway, never by the buyer or the AI. Every move goes through
 * MoveLeadToStage and is written to the lead's activity. Only for industry "digital"; must run inside the tenant.
 */
class DigitalStageAutomation
{
    public const ACCESS_MESSAGE = "Halo {nama}, pembayaran {produk} sudah kami terima. Terima kasih 🙏\nAkses Anda: {tautan}\nKalau ada kendala membuka, balas chat ini ya.";

    private ?DigitalStages $stages = null;

    public function __construct(private CurrentTenant $current, private MoveLeadToStage $move, private RouteSelector $routes) {}

    public function applies(): bool
    {
        return DigitalStageAutomationSettings::appliesTo($this->current->get());
    }

    public function on(string $rule): bool
    {
        return $this->applies() && DigitalStageAutomationSettings::of($this->current->get())->enabled($rule);
    }

    public function stages(): DigitalStages
    {
        return $this->stages ??= new DigitalStages;
    }

    /** The buyer confirmed the purchase: it waits for payment. */
    public function purchaseRecorded(Lead $lead): ?string
    {
        return $this->on('purchase_recorded') ? $this->forward($lead, DigitalStages::PAYMENT, 'pembeli konfirmasi pembelian') : null;
    }

    public function inbound(Lead $lead, WaMessage $message): void
    {
        if (! $this->applies()) {
            return;
        }

        $lead->loadMissing('stage');

        if ($message->type === 'image' && $this->on('payment_proof') && $this->stages()->is($lead->stage, DigitalStages::PAYMENT) && $lead->paid_at === null) {
            $this->paymentProof($lead);

            return;
        }

        if ($this->on('reopen') && $this->stages()->is($lead->stage, DigitalStages::UNPAID)) {
            $this->moveTo($lead, DigitalStages::NEW, 'pembeli yang tidak jadi bayar mengirim chat baru');
        }
    }

    /** Flags the lead for a person to check; nothing here (or the AI) says the payment was received. */
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
                Mail::to($person->email)->send(new PaymentProofReceived($tenant, $lead, 'agar pembelian pindah ke Lunas & akses terkirim'));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    public function canConfirmPayment(?Lead $lead): bool
    {
        return $lead !== null && $this->applies() && $lead->paid_at === null
            && ($lead->payment_proof_at !== null || $this->stages()->is($lead->stage, DigitalStages::PAYMENT));
    }

    /**
     * "Pembayaran diterima": a person checked the money. The lead is marked paid and moves to "Lunas & akses
     * terkirim"; with an access link, the link is kept and, when asked, sent to the buyer on WhatsApp.
     *
     * @return string what happened, for the person who pressed it
     */
    public function paymentReceived(Lead $lead, User $actor, ?string $accessLink = null, bool $send = false): string
    {
        $link = filled($accessLink) ? trim((string) $accessLink) : null;

        $moved = DB::transaction(function () use ($lead, $actor, $link) {
            $lead->forceFill(array_filter(['access_link' => $link]) + ['paid_at' => now(), 'payment_proof_at' => null])->save();
            $lead->activities()->create(['user_id' => $actor->getKey(), 'type' => 'payment_received', 'body' => 'Pembayaran dikonfirmasi diterima oleh '.$actor->name]);

            $target = $this->stages()->stage(DigitalStages::PAID);
            $lead->loadMissing('stage');

            if ($target === null || ! $this->before($lead->stage, $target)) {
                return false;
            }

            try {
                ($this->move)($lead, $target, $actor, $this->paidDetails($lead));
            } catch (ValidationException $e) {
                report($e);

                return false;
            }

            return true;
        });

        $note = $moved ? 'Pembayaran dicatat diterima dan lead pindah ke '.$this->stages()->stage(DigitalStages::PAID)?->name.'.' : 'Pembayaran dicatat diterima.';

        return $link !== null && $send ? $note.' '.$this->sendAccess($lead->refresh(), $actor) : $note;
    }

    private function sendAccess(Lead $lead, User $actor): string
    {
        $conversation = filled($lead->phone) ? WaConversation::query()->firstOrCreate(['lead_id' => $lead->getKey()], ['phone' => $lead->phone]) : null;
        $decision = $conversation ? $this->routes->decide($conversation) : null;

        if ($decision === null || ! $decision->canSend() || $decision->isPaid()) {
            $why = $decision?->isPaid() ? 'jendela chat gratis sudah tutup' : ($decision?->note ?: 'lead belum punya nomor WhatsApp');
            $lead->activities()->create(['type' => 'access_not_sent', 'body' => "Tautan akses belum terkirim ke pembeli: {$why}. Kirim manual dari Inbox."]);

            return 'Tautan akses belum terkirim: '.$why.'.';
        }

        $text = strtr(self::ACCESS_MESSAGE, ['{nama}' => $lead->name, '{produk}' => $lead->property?->name ?? 'produk', '{tautan}' => $lead->access_link]);

        try {
            $message = app(SendWhatsAppMessage::class)($conversation, $actor, $text);
        } catch (Throwable $e) {
            $lead->activities()->create(['type' => 'access_not_sent', 'body' => "Tautan akses gagal terkirim ke pembeli: {$e->getMessage()}"]);

            return 'Tautan akses gagal terkirim: '.$e->getMessage();
        }

        return $message->status === MessageStatus::Failed ? 'Tautan akses gagal terkirim: '.$message->error : 'Tautan akses dikirim ke pembeli lewat WhatsApp.';
    }

    /** @return array{no_payment: int} */
    public function runScheduled(Tenant $tenant): array
    {
        return $this->current->run($tenant, function () {
            $this->stages = null;
            $count = ['no_payment' => 0];

            if (! $this->applies() || PlanLimits::for($this->current->get())->readOnly() || ! $this->on('no_payment')) {
                return $count;
            }

            $stage = $this->stages()->stage(DigitalStages::PAYMENT);
            $hours = DigitalStageAutomationSettings::of($this->current->get())->number('no_payment', 'hours');

            foreach ($stage ? Lead::query()->where('stage_id', $stage->getKey())->whereNull('payment_proof_at')->whereNull('paid_at')->get() : [] as $lead) {
                if ($lead->stageEnteredAt()->lte(now()->subHours($hours))
                    && $this->moveTo($lead, DigitalStages::UNPAID, "belum ada pembayaran {$hours} jam", $this->lostDetails('Pembayaran gagal'))) {
                    $count['no_payment']++;
                }
            }

            return $count;
        });
    }

    private function paidDetails(Lead $lead): array
    {
        $price = (int) ($lead->property?->price_from ?? 0) * max(1, (int) $lead->quantity);

        return [
            'unit' => $lead->unit ?: trim(($lead->property?->name ?? 'Pembelian').($lead->quantity > 1 ? ' x'.$lead->quantity : '')),
            'deal_value' => max(1, (int) ($lead->deal_value ?: $price)),
        ];
    }

    private function lostDetails(string $reason): array
    {
        return ['lost_reason_id' => LostReason::query()->whereRaw('lower(label) = ?', [mb_strtolower($reason)])->value('id')
            ?? LostReason::query()->create(['label' => $reason])->getKey()];
    }

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

    private function staff(Tenant $tenant, Lead $lead)
    {
        return $tenant->activeUsers()->get()->filter(fn (User $u) => $u->getKey() === $lead->owner_id || $u->pivot->role === Role::Admin)->values();
    }
}
