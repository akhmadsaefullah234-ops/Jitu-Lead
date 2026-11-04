<?php

namespace App\LeadForms;

use App\Models\Lead;
use App\Models\LeadFormRejection;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tells robots from buyers on public forms without ever losing a buyer.
 *
 * The trap is a text field with a meaningless name ("lf_hp_" + a token made per page view) inside a container that
 * is display:none, so the browser's autofill (the Facebook and Instagram in-app browsers included) leaves it alone.
 * Next to it, the time the page was made, signed, so a robot that posts at once is recognised.
 *
 * - Trap empty: a normal submission.
 * - Trap filled, but a valid WhatsApp number and not instant: saved as usual, marked "dicurigai spam" with the
 *   reasons, and the visitor still goes on to WhatsApp (a real buyer whose browser filled the trap is not lost).
 * - Trap filled AND (sent within MIN_SECONDS of the page loading, or no valid WhatsApp number): rejected, logged
 *   (no personal data) and counted per form (shown on the Formulir list for 7 days).
 *
 * The old trap field "website" still counts, for pages opened before this change and for agencies' own HTML forms.
 */
final class SpamGuard
{
    public const TRAP_PREFIX = 'lf_hp_';

    public const STAMP = 'lf_ts';

    public const LEGACY_TRAP = 'website';

    public const MIN_SECONDS = 2;

    public const OK = 'ok';

    public const SUSPECT = 'suspect';

    public const REJECT = 'reject';

    /** @return array{trap: string, stamp: string} the field names and the signed time for one page view */
    public static function fields(): array
    {
        $time = (string) now()->getTimestamp();

        return ['trap' => self::TRAP_PREFIX.Str::lower(Str::random(8)), 'stamp' => $time.'.'.self::sign($time)];
    }

    /**
     * What to do with a submission.
     *
     * @return array{verdict: string, reasons: list<string>}
     */
    public static function check(Request $request, ?string $phone): array
    {
        $trapped = filled($request->input(self::LEGACY_TRAP)) || collect($request->all())
            ->some(fn ($value, $key) => is_string($key) && str_starts_with($key, self::TRAP_PREFIX) && filled($value));

        if (! $trapped) {
            return ['verdict' => self::OK, 'reasons' => []];
        }

        $reasons = ['Kolom jebakan robot terisi'];
        $seconds = self::secondsSinceLoad($request);
        $strong = false;

        if ($seconds !== null && $seconds < self::MIN_SECONDS) {
            $reasons[] = "Dikirim {$seconds} detik setelah halaman dibuka";
            $strong = true;
        }

        if (PhoneNumber::whatsapp($phone) === null) {
            $reasons[] = 'Nomor WhatsApp tidak valid';
            $strong = true;
        }

        return ['verdict' => $strong ? self::REJECT : self::SUSPECT, 'reasons' => $reasons];
    }

    /** Seconds between the page being made and this submission; null without a valid signed time. */
    public static function secondsSinceLoad(Request $request): ?int
    {
        $stamp = (string) $request->input(self::STAMP, '');

        if (! preg_match('/^(\d{9,11})\.([a-f0-9]{16})$/', $stamp, $m) || ! hash_equals(self::sign($m[1]), $m[2])) {
            return null;
        }

        return max(0, now()->getTimestamp() - (int) $m[1]);
    }

    /** Logs and counts a rejected submission; nothing about the person is kept. */
    public static function reject(Request $request, int $tenantId, ?int $formId, string $where, array $reasons): void
    {
        Log::warning('Public form submission rejected as spam', [
            'tenant' => $tenantId, 'form' => $formId, 'endpoint' => $where,
            'reasons' => $reasons, 'user_agent' => Str::limit((string) $request->userAgent(), 300, ''),
        ]);

        LeadFormRejection::query()->create(['tenant_id' => $tenantId, 'lead_form_id' => $formId, 'reason' => Str::limit(implode('; ', $reasons), 250, '')]);
    }

    /** Marks a saved lead as suspected spam (a new lead only: an existing lead is not marked by one odd submission), with a note. */
    public static function mark(Lead $lead, bool $created, array $reasons, ?string $userAgent): void
    {
        if ($created) {
            $lead->custom_fields = array_replace((array) $lead->custom_fields, ['spam' => ['reasons' => $reasons, 'at' => now()->toIso8601String()]]);
            $lead->save();
        }

        $lead->activities()->create([
            'type' => 'note',
            'body' => 'Dicurigai spam: '.implode(', ', $reasons).'. Lead tetap disimpan dan diproses seperti biasa; periksa sebelum menghapus.',
            'meta' => ['spam' => $reasons, 'user_agent' => Str::limit((string) $userAgent, 300, '')],
        ]);
    }

    private static function sign(string $time): string
    {
        return substr(hash_hmac('sha256', 'lead-form-stamp:'.$time, (string) config('app.key')), 0, 16);
    }
}
