<?php

namespace App\Campaigns;

use App\Jobs\SendCampaignEmail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignSend;
use App\Models\User;
use App\Retention\DailyEmailQuota;
use Illuminate\Support\Facades\DB;

/**
 * The scheduled pass over running campaigns (campaigns:send).
 *
 * At the start time the first email's audience is fixed from the segment. Each later email goes to whoever got
 * the one before, interval_days after it, if they can still be reached (and have not subscribed, when the
 * campaign stops for subscribers). Every email is a row per person that moves pending -> queued -> sent/failed,
 * so nothing goes twice. Queuing stops at the daily cap shared with the trial emails; the rest waits a day.
 */
class CampaignRunner
{
    /** @return array{queued: int, skipped: int, finished: int, limited: bool} */
    public function run(): array
    {
        $result = ['queued' => 0, 'skipped' => 0, 'finished' => 0, 'limited' => false];

        $campaigns = EmailCampaign::query()->where('status', EmailCampaign::RUNNING)->where('starts_at', '<=', now())->orderBy('starts_at')->get();

        foreach ($campaigns as $campaign) {
            $this->freezeAudience($campaign);
            $result['skipped'] += $this->advance($campaign);
        }

        foreach ($campaigns as $campaign) {
            [$queued, $skipped, $limited] = $this->dispatch($campaign);
            $result['queued'] += $queued;
            $result['skipped'] += $skipped;

            if ($limited) {
                $result['limited'] = true;
                break;
            }
        }

        foreach ($campaigns as $campaign) {
            if ($this->finishIfDone($campaign)) {
                $result['finished']++;
            }
        }

        return $result;
    }

    /** Stops a campaign for good: emails still waiting are cancelled. */
    public static function stop(EmailCampaign $campaign): void
    {
        DB::transaction(function () use ($campaign) {
            $campaign->update(['status' => EmailCampaign::STOPPED]);
            $campaign->sends()->where('status', EmailCampaignSend::PENDING)->update(['status' => EmailCampaignSend::CANCELLED]);
        });
    }

    private function freezeAudience(EmailCampaign $campaign): void
    {
        if ($campaign->audience_frozen_at !== null) {
            return;
        }

        $now = now();
        CampaignAudience::query($campaign->segment)->select('users.id')->orderBy('users.id')->chunk(500, function ($users) use ($campaign, $now) {
            EmailCampaignSend::query()->insertOrIgnore($users->map(fn (User $u) => [
                'email_campaign_id' => $campaign->getKey(), 'step' => 0, 'user_id' => $u->getKey(),
                'status' => EmailCampaignSend::PENDING, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        });

        $campaign->update(['audience_frozen_at' => $now]);
    }

    /** Opens the next email for everyone whose previous one went out interval_days ago. @return int people closed off */
    private function advance(EmailCampaign $campaign): int
    {
        $closed = 0;
        $last = count($campaign->emails()) - 1;

        for ($step = 0; $step < $last; $step++) {
            $ready = $campaign->sends()->with('user')
                ->where('step', $step)->where('status', EmailCampaignSend::SENT)
                ->where('sent_at', '<=', now()->subDays($campaign->interval_days))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('email_campaign_sends as next')
                    ->whereColumn('next.email_campaign_id', 'email_campaign_sends.email_campaign_id')
                    ->whereColumn('next.user_id', 'email_campaign_sends.user_id')
                    ->where('next.step', $step + 1))
                ->get();

            foreach ($ready as $previous) {
                $why = $this->blocked($campaign, $previous->user);
                EmailCampaignSend::query()->insertOrIgnore([[
                    'email_campaign_id' => $campaign->getKey(), 'step' => $step + 1, 'user_id' => $previous->user_id,
                    'status' => $why === null ? EmailCampaignSend::PENDING : EmailCampaignSend::SKIPPED, 'error' => $why,
                    'created_at' => now(), 'updated_at' => now(),
                ]]);
                $closed += $why === null ? 0 : 1;
            }
        }

        return $closed;
    }

    /** @return array{0: int, 1: int, 2: bool} queued, skipped, stopped by the daily cap */
    private function dispatch(EmailCampaign $campaign): array
    {
        $queued = $skipped = 0;
        $pending = $campaign->sends()->with('user')->where('status', EmailCampaignSend::PENDING)->lazyById(200);

        foreach ($pending as $send) {
            if (($why = $this->blocked($campaign, $send->user)) !== null) {
                $send->update(['status' => EmailCampaignSend::SKIPPED, 'error' => $why]);
                $skipped++;

                continue;
            }

            if (DailyEmailQuota::remaining() < 1) {
                return [$queued, $skipped, true];
            }

            $send->update(['status' => EmailCampaignSend::QUEUED, 'queued_at' => now()]);
            SendCampaignEmail::dispatch($send->getKey());
            $queued++;
        }

        return [$queued, $skipped, false];
    }

    /** Why this person must not get the next email, or null when they may. */
    private function blocked(EmailCampaign $campaign, ?User $user): ?string
    {
        return match (true) {
            $user === null => 'Pengguna dihapus',
            $user->trial_emails_unsubscribed_at !== null => 'Berhenti berlangganan',
            $user->contact_consent_at === null => 'Tidak ada persetujuan',
            $campaign->stop_when_subscribed && CampaignAudience::subscribed($user) => 'Sudah berlangganan',
            default => null,
        };
    }

    /** Done when nothing waits and nobody can get another email. */
    private function finishIfDone(EmailCampaign $campaign): bool
    {
        $waiting = $campaign->sends()->whereIn('status', [EmailCampaignSend::PENDING, EmailCampaignSend::QUEUED])->exists();
        $last = count($campaign->emails()) - 1;
        $open = $campaign->sends()->where('step', '<', $last)->where('status', EmailCampaignSend::SENT)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('email_campaign_sends as next')
                ->whereColumn('next.email_campaign_id', 'email_campaign_sends.email_campaign_id')
                ->whereColumn('next.user_id', 'email_campaign_sends.user_id')
                ->whereColumn('next.step', '>', 'email_campaign_sends.step'))
            ->exists();

        if ($waiting || $open) {
            return false;
        }

        $campaign->update(['status' => EmailCampaign::FINISHED]);

        return true;
    }
}
