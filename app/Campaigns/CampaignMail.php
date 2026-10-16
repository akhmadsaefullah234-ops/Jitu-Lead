<?php

namespace App\Campaigns;

use App\Mail\TrialEmail;
use App\Models\EmailCampaign;
use App\Models\User;
use App\Retention\TrialEmails;
use App\Retention\TrialEmailSettings;

/** Fills in one campaign email for one person, in the same branded layout as the trial emails. */
class CampaignMail
{
    public const PLACEHOLDERS = ['{nama}', '{agensi}'];

    public static function make(EmailCampaign $campaign, int $step, User $user, bool $test = false): TrialEmail
    {
        $email = $campaign->emails()[$step];
        $values = [
            '{nama}' => $user->name,
            '{agensi}' => (string) $user->activeTenants()->orderBy('tenants.id')->value('name'),
        ];
        $button = trim((string) ($email['button'] ?? ''));
        $url = trim((string) ($email['url'] ?? ''));

        return new TrialEmail(
            step: "campaign-{$campaign->getKey()}-".($step + 1),
            subjectLine: ($test ? '[UJI] ' : '').strtr((string) $email['subject'], $values),
            body: strtr((string) $email['body'], array_map(fn ($v) => e($v), $values)),
            button: $url !== '' ? $button : '',
            url: $url !== '' ? $url : (string) config('app.url'),
            unsubscribeUrl: TrialEmails::unsubscribeUrl($user, $test ? null : $campaign->getKey()),
            fromAddress: TrialEmailSettings::fromAddress(),
            fromName: (string) config('trial_emails.from_name'),
            reason: 'Anda menerima email ini karena bersedia dihubungi tentang akun dan penawaran JITU LEAD.',
        );
    }
}
