<?php

namespace App\Retention;

use App\Models\PlatformSetting;
use Illuminate\Support\Str;

/**
 * The trial email series as the platform owner set it in /admin, on top of the defaults in config/trial_emails.php.
 * Saved as the "trial_emails" platform setting: ['enabled' => bool, 'steps' => [key => [enabled, subject, button, body]]].
 */
class TrialEmailSettings
{
    public const EDITABLE = ['enabled', 'subject', 'button', 'body'];

    public const PLACEHOLDERS = ['{nama}', '{agensi}', '{hari_tersisa}', '{tanggal_berakhir}', '{jumlah_lead}', '{jumlah_pesan}', '{paket_percobaan}', '{katalog}', '{isi_pengetahuan}'];

    public static function saved(): array
    {
        try {
            return PlatformSetting::read('trial_emails');
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    public static function enabled(): bool
    {
        return (bool) (self::saved()['enabled'] ?? config('trial_emails.enabled'));
    }

    /** @return array<string, array{label: string, from: string, days: int, link: string, subject: string, button: string, body: string, enabled: bool}> */
    public static function steps(): array
    {
        $saved = (array) (self::saved()['steps'] ?? []);

        return collect(config('trial_emails.steps'))->map(function (array $step, string $key) use ($saved) {
            $own = array_filter(array_intersect_key((array) ($saved[$key] ?? []), array_flip(self::EDITABLE)), fn ($v) => $v !== null && $v !== '');

            return array_replace($step + ['enabled' => true], $own, ['enabled' => (bool) ($own['enabled'] ?? true)]);
        })->all();
    }

    /** True when the series sends the "trial ends soon" reminders, so the old single reminder must not go to trials too. */
    public static function coversEndingReminders(): bool
    {
        if (! self::enabled()) {
            return false;
        }

        return collect(self::steps())->contains(fn (array $s) => $s['from'] === 'end' && $s['days'] < 0 && $s['enabled']);
    }

    public static function fromAddress(): string
    {
        if ($from = config('trial_emails.from_address')) {
            return $from;
        }

        $address = (string) config('mail.from.address');

        return 'noreply@'.(str_contains($address, '@') ? Str::after($address, '@') : 'localhost');
    }
}
