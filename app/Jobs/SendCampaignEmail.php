<?php

namespace App\Jobs;

use App\Campaigns\CampaignMail;
use App\Models\EmailCampaignSend;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Sends one campaign email and records whether it went out, for the campaign's statistics. */
class SendCampaignEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(public int $sendId) {}

    public function handle(): void
    {
        $send = EmailCampaignSend::query()->with(['campaign', 'user'])->find($this->sendId);

        if ($send === null || $send->status !== EmailCampaignSend::QUEUED || $send->user === null) {
            return;
        }

        $email = $send->campaign->emails()[$send->step] ?? null;

        if ($email === null) {
            $send->update(['status' => EmailCampaignSend::SKIPPED, 'error' => 'Email tidak ada lagi di kampanye']);

            return;
        }

        Mail::to($send->user->email)->sendNow(CampaignMail::make($send->campaign, $send->step, $send->user));
        $send->update(['status' => EmailCampaignSend::SENT, 'sent_at' => now(), 'error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        EmailCampaignSend::query()->whereKey($this->sendId)
            ->update(['status' => EmailCampaignSend::FAILED, 'error' => mb_substr((string) $e?->getMessage(), 0, 250)]);
    }
}
