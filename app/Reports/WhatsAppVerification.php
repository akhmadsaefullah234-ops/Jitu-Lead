<?php

namespace App\Reports;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Confirms a person's WhatsApp number before reports go to it: a 6-digit code is sent to the number from the
 * platform's report number, and the number is saved on the profile (verified) once the code is typed back.
 * A code lasts 10 minutes and allows 5 tries; at most 3 codes are sent per 10 minutes.
 */
class WhatsAppVerification
{
    public const MINUTES = 10;

    public const TRIES = 5;

    public const SENDS = 3;

    public function __construct(private DailyReportSender $sender) {}

    /** Sends a code to $number; null when it went, else a message for the person. */
    public function send(User $user, string $number): ?string
    {
        $digits = PhoneNumber::whatsapp($number);

        if ($digits === null) {
            return 'Isi nomor WhatsApp Indonesia yang valid, misalnya 0812 3456 7890.';
        }

        $limit = 'report-wa-code-send:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($limit, self::SENDS)) {
            return 'Terlalu sering meminta kode. Coba lagi dalam '.max(1, (int) ceil(RateLimiter::availableIn($limit) / 60)).' menit.';
        }

        RateLimiter::hit($limit, self::MINUTES * 60);
        $code = (string) random_int(100000, 999999);

        $problem = $this->sender->whatsapp($digits, "Kode verifikasi nomor WhatsApp untuk laporan JITU LEAD: *{$code}*\n\nBerlaku ".self::MINUTES.' menit. Jangan berikan kode ini kepada siapa pun.');

        if ($problem !== null) {
            Cache::forget($this->key($user));

            return 'Kode tidak bisa dikirim lewat WhatsApp saat ini ('.$problem.') Laporan tetap dikirim lewat email sampai nomor terverifikasi.';
        }

        Cache::put($this->key($user), ['hash' => $this->hash($user, $code), 'number' => $digits, 'tries' => 0], now()->addMinutes(self::MINUTES));

        return null;
    }

    /** The number a code is waiting for, if any. */
    public function pending(User $user): ?string
    {
        return Cache::get($this->key($user))['number'] ?? null;
    }

    /** Checks the code; null when the number is now verified, else a message for the person. */
    public function verify(User $user, string $code): ?string
    {
        $pending = Cache::get($this->key($user));

        if (! is_array($pending)) {
            return 'Kode sudah kedaluwarsa. Minta kode baru.';
        }

        if (! hash_equals($pending['hash'], $this->hash($user, preg_replace('/\D+/', '', $code)))) {
            $pending['tries']++;

            if ($pending['tries'] >= self::TRIES) {
                Cache::forget($this->key($user));

                return 'Kode salah '.self::TRIES.' kali. Minta kode baru.';
            }

            Cache::put($this->key($user), $pending, now()->addMinutes(self::MINUTES));

            return 'Kode salah.';
        }

        Cache::forget($this->key($user));
        $user->forceFill(['whatsapp' => $pending['number'], 'whatsapp_verified_number' => $pending['number'], 'whatsapp_verified_at' => now()])->save();

        return null;
    }

    private function key(User $user): string
    {
        return 'report-wa-code:'.$user->getKey();
    }

    private function hash(User $user, string $code): string
    {
        return hash_hmac('sha256', $user->getKey().':'.$code, (string) config('app.key'));
    }
}
