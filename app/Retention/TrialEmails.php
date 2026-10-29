<?php

namespace App\Retention;

use App\Enums\Role;
use App\Mail\TrialEmail;
use App\Models\Lead;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TrialEmailLog;
use App\Models\User;
use App\Models\WaMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * The daily run of the trial email series. For each agency that never paid it works out which email is due
 * today, sends it to the agency's verified admins who did not unsubscribe, and records it so it never goes
 * twice. At most one email per agency per day, and no more than the daily limit in total.
 */
class TrialEmails
{
    /** @return array{sent: int, skipped: int, agencies: int, limited: bool} */
    public function run(): array
    {
        $result = ['sent' => 0, 'skipped' => 0, 'agencies' => 0, 'limited' => false];

        if (! TrialEmailSettings::enabled()) {
            return $result;
        }

        $steps = collect(TrialEmailSettings::steps())->filter(fn (array $s) => $s['enabled']);
        $left = DailyEmailQuota::remaining();
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $oldest = $today->subDays(max(0, (int) collect(config('trial_emails.steps'))->max('days')) + (int) config('trial_emails.window_days') + 1);

        $subscriptions = Subscription::query()
            ->whereNull('current_period_end')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>=', $oldest)
            ->whereHas('tenant', fn ($q) => $q->where('status', '!=', 'suspended'))
            ->with('tenant')
            ->orderBy('id')
            ->get();

        foreach ($subscriptions as $sub) {
            $due = $this->due($sub, $steps, $today);

            if ($due === null) {
                continue;
            }

            [$key, $anchor, $older] = $due;
            $recipients = $this->recipients($sub->tenant);

            if ($recipients->isEmpty()) {
                continue;
            }

            if ($recipients->count() > $left) {
                $result['limited'] = true;
                break;
            }

            foreach ($recipients as $user) {
                Mail::to($user->email)->queue($this->mail($key, $steps[$key], $sub, $user));
            }

            $this->log($sub->tenant, $key, $anchor, TrialEmailLog::SENT, $recipients->count());

            // An earlier email of the same kind that did not go out in time is now out of date.
            foreach ($older as [$oldKey, $oldAnchor]) {
                $this->log($sub->tenant, $oldKey, $oldAnchor, TrialEmailLog::SKIPPED, 0);
                $result['skipped']++;
            }

            $left -= $recipients->count();
            $result['sent'] += $recipients->count();
            $result['agencies']++;
        }

        return $result;
    }

    /**
     * The email due today for this agency: the latest one whose day has come, is still within the window, and was
     * not logged yet, plus the earlier ones of the same group it replaces.
     *
     * @return array{0: string, 1: CarbonImmutable, 2: list<array{0: string, 1: CarbonImmutable}>}|null
     */
    public function due(Subscription $sub, Collection $steps, CarbonImmutable $today): ?array
    {
        $tz = config('app.timezone');
        $signup = CarbonImmutable::instance($sub->tenant->created_at)->setTimezone($tz)->startOfDay();
        $end = CarbonImmutable::instance($sub->trial_ends_at)->setTimezone($tz)->startOfDay();
        $ended = $sub->trial_ends_at->isPast();
        $window = (int) config('trial_emails.window_days');
        $logged = TrialEmailLog::query()->where('tenant_id', $sub->tenant_id)->get()
            ->map(fn (TrialEmailLog $l) => $l->step.'|'.$l->anchor->format('Y-m-d'))->all();

        $candidates = $steps->map(function (array $s, string $key) use ($signup, $end, $ended, $today, $window, $logged) {
            $anchor = $s['from'] === 'signup' ? $signup : $end;
            $day = $anchor->addDays($s['days']);

            $fits = match (true) {
                // Tips during the trial make no sense once it is over.
                $s['from'] === 'signup' => ! $ended,
                $s['days'] < 0 => ! $ended,
                default => $ended,
            };

            if (! $fits || $day->greaterThan($today) || $day->addDays($window)->lessThanOrEqualTo($today) || in_array($key.'|'.$anchor->format('Y-m-d'), $logged, true)) {
                return null;
            }

            return ['key' => $key, 'group' => $s['from'].($s['days'] < 0 ? '-before' : ''), 'anchor' => $anchor, 'day' => $day];
        })->filter()->sortBy(fn ($c) => $c['day']->getTimestamp())->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $pick = $candidates->last();
        $older = $candidates->slice(0, -1)->where('group', $pick['group'])->map(fn ($c) => [$c['key'], $c['anchor']])->values()->all();

        return [$pick['key'], $pick['anchor'], $older];
    }

    /** @return Collection<int, User> */
    public function recipients(Tenant $tenant): Collection
    {
        return $tenant->activeUsers()
            ->wherePivot('role', Role::Admin->value)
            ->whereNotNull('email_verified_at')
            ->whereNull('trial_emails_unsubscribed_at')
            ->orderBy('users.id')
            ->get();
    }

    public function mail(string $key, array $step, Subscription $sub, User $user): TrialEmail
    {
        $tenant = $sub->tenant;
        $values = $this->values($sub, $user);
        $fill = fn (string $text, bool $escape) => strtr($text, $escape ? array_map(fn ($v) => e($v), $values) : $values);

        return new TrialEmail(
            step: $key,
            subjectLine: $fill($step['subject'], false),
            body: $fill($step['body'], true),
            button: trim((string) $step['button']),
            url: $this->link($step['link'], $tenant),
            unsubscribeUrl: self::unsubscribeUrl($user),
            fromAddress: TrialEmailSettings::fromAddress(),
            fromName: (string) config('trial_emails.from_name'),
        );
    }

    /** $campaign: the promo campaign the email belongs to, so its statistics can count the unsubscribe. */
    public static function unsubscribeUrl(User $user, ?int $campaign = null): string
    {
        return URL::signedRoute('trial-emails.unsubscribe', array_filter(['user' => $user->getKey(), 'kampanye' => $campaign]));
    }

    /** @return array<string, string> */
    private function values(Subscription $sub, User $user): array
    {
        $tenant = $sub->tenant;
        $tz = config('app.timezone');
        $end = $sub->trial_ends_at->copy()->timezone($tz);
        $daysLeft = max(0, (int) CarbonImmutable::now($tz)->startOfDay()->diffInDays($end->copy()->startOfDay(), false));

        return [
            '{nama}' => $user->name,
            '{agensi}' => $tenant->name,
            '{hari_tersisa}' => (string) $daysLeft,
            '{tanggal_berakhir}' => $end->translatedFormat('j F Y'),
            '{jumlah_lead}' => (string) Lead::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->whereNull('deleted_at')->count(),
            '{jumlah_pesan}' => (string) WaMessage::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->count(),
            '{paket_percobaan}' => (string) config('plans.plans.'.config('plans.trial_plan').'.name'),
            '{katalog}' => $tenant->industry()->term('catalog_item'),
            '{isi_pengetahuan}' => $tenant->industry()->term('knowledge_examples'),
        ];
    }

    private function link(string $link, Tenant $tenant): string
    {
        return route(match ($link) {
            'whatsapp' => 'filament.app.resources.wa-channels.index',
            'knowledge' => 'filament.app.resources.ai-knowledge.index',
            'subscription' => 'filament.app.pages.subscription',
            default => 'filament.app.pages.dashboard',
        }, ['tenant' => $tenant->slug]);
    }

    private function log(Tenant $tenant, string $step, CarbonImmutable $anchor, string $status, int $recipients): void
    {
        TrialEmailLog::query()->firstOrCreate(
            ['tenant_id' => $tenant->getKey(), 'step' => $step, 'anchor' => $anchor->format('Y-m-d')],
            ['status' => $status, 'recipients' => $recipients],
        );
    }
}
