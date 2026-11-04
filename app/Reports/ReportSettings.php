<?php

namespace App\Reports;

use App\Enums\Role;
use App\Models\Membership;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * Who gets the daily report and when. The agency sets the send time and days (Pengaturan > Laporan harian); each
 * member switches it on or off for themself, may pick their own time and days, and adds the weekly (Monday
 * morning, the 7 days before) and monthly (the 1st, last month) summaries. A member who never chose gets the
 * default of their role: on for admins (the owner), off for team leaders and agents.
 */
final class ReportSettings
{
    public const DAYS = [
        'daily' => 'Setiap hari',
        'mon_sat' => 'Senin–Sabtu',
    ];

    public const DEFAULT_TIME = '07:00';

    public const DEFAULT_DAYS = 'daily';

    /** @return array<string, string> "HH:MM" every 30 minutes from 05:00 to 22:00 */
    public static function times(): array
    {
        $times = [];

        for ($m = 5 * 60; $m <= 22 * 60; $m += 30) {
            $t = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
            $times[$t] = $t;
        }

        return $times;
    }

    /** @return array{time: string, days: string} */
    public static function agency(Tenant $tenant): array
    {
        $saved = (array) $tenant->daily_report;

        return [
            'time' => self::time($saved['time'] ?? null) ?? self::DEFAULT_TIME,
            'days' => self::days($saved['days'] ?? null) ?? self::DEFAULT_DAYS,
        ];
    }

    /** @param  array<string, mixed>  $state */
    public static function saveAgency(Tenant $tenant, array $state): void
    {
        $tenant->forceFill(['daily_report' => [
            'time' => self::time($state['time'] ?? null) ?? self::DEFAULT_TIME,
            'days' => self::days($state['days'] ?? null) ?? self::DEFAULT_DAYS,
        ]])->save();
    }

    public static function defaultOn(?Role $role): bool
    {
        return $role === Role::Admin;
    }

    /**
     * The member's own choice; time and days null mean "as the agency".
     *
     * @return array{enabled: bool, time: ?string, days: ?string, weekly: bool, monthly: bool}
     */
    public static function member(Membership $membership): array
    {
        $saved = (array) $membership->report_settings;

        return [
            'enabled' => array_key_exists('enabled', $saved) && $saved['enabled'] !== null ? (bool) $saved['enabled'] : self::defaultOn($membership->role),
            'time' => self::time($saved['time'] ?? null),
            'days' => self::days($saved['days'] ?? null),
            'weekly' => (bool) ($saved['weekly'] ?? false),
            'monthly' => (bool) ($saved['monthly'] ?? false),
        ];
    }

    /** @param  array<string, mixed>  $state */
    public static function saveMember(Membership $membership, array $state): void
    {
        $membership->forceFill(['report_settings' => [
            'enabled' => (bool) ($state['enabled'] ?? false),
            'time' => self::time($state['time'] ?? null),
            'days' => self::days($state['days'] ?? null),
            'weekly' => (bool) ($state['weekly'] ?? false),
            'monthly' => (bool) ($state['monthly'] ?? false),
        ]])->save();
    }

    /**
     * What the member gets: on or off, and the time and days that apply (theirs, else the agency's).
     *
     * @return array{enabled: bool, time: string, days: string, weekly: bool, monthly: bool}
     */
    public static function effective(Membership $membership, Tenant $tenant): array
    {
        $mine = self::member($membership);
        $agency = self::agency($tenant);

        return ['time' => $mine['time'] ?? $agency['time'], 'days' => $mine['days'] ?? $agency['days']] + $mine;
    }

    /**
     * The reports due on the agency's local day $today (a CarbonImmutable in its time zone), with when each is due.
     * Monday brings the weekly summary and the 1st the monthly one, at the same time as the daily report, even on a
     * day the daily one is not sent (a Sunday with "Senin–Sabtu" never is: Monday and the 1st may be).
     *
     * @return array<string, CarbonImmutable> kind => due time, in the agency's zone
     */
    public static function dueOn(array $effective, CarbonImmutable $today): array
    {
        if (! $effective['enabled']) {
            return [];
        }

        [$h, $m] = array_map('intval', explode(':', $effective['time']));
        $at = $today->startOfDay()->setTime($h, $m);
        $due = [];

        if ($effective['days'] === 'daily' || ! $today->isSunday()) {
            $due['daily'] = $at;
        }

        if ($effective['weekly'] && $today->isMonday()) {
            $due['weekly'] = $at;
        }

        if ($effective['monthly'] && $today->day === 1) {
            $due['monthly'] = $at;
        }

        return $due;
    }

    private static function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : null;
    }

    private static function days(mixed $value): ?string
    {
        return is_string($value) && array_key_exists($value, self::DAYS) ? $value : null;
    }
}
