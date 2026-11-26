<?php

namespace App\Shipping\Shipments;

use App\Models\Tenant;
use App\Shipping\Carriers\ShipmentStatus;
use Illuminate\Support\Carbon;

/**
 * A shop's settings for the daily follow-up of parcels the courier could not deliver (tenants.parcel_follow_up,
 * Pengaturan > Otomatisasi tahap). Off until an admin switches it on; enabled_at remembers when, so only failures of
 * the BACKLOG_DAYS before that day take part.
 */
final class ParcelFollowUpSettings
{
    /** Send hour (agency time): from MIN_HOUR to MAX_HOUR on the hour. */
    public const MIN_HOUR = 9;

    public const MAX_HOUR = 17;

    public const DEFAULT_HOUR = 10;

    /** A run that missed the send hour still sends until this hour (exclusive) the same day. */
    public const SEND_UNTIL = 18;

    public const DEFAULT_LIMIT = 7;

    public const MAX_LIMIT = 60;

    /** Monday to Saturday (ISO 1 = Monday, 7 = Sunday). */
    public const DEFAULT_DAYS = [1, 2, 3, 4, 5, 6];

    public const DAY_NAMES = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    public const MIN_TEXTS = 3;

    /** Minimum hours between two messages to the buyer about the parcel (also after the courier status message). */
    public const MIN_GAP_HOURS = 20;

    /** On switching on, only failures from this many days back take part; older ones are listed to handle by hand. */
    public const BACKLOG_DAYS = 7;

    public const VARIABLES = ['nama', 'alasan', 'resi', 'kurir'];

    public const DEFAULT_TEXTS = [
        'Halo kak {nama} 🙏 Paket kakak (resi {resi}, {kurir}) belum berhasil diantar: {alasan}. Boleh dibantu konfirmasi alamat lengkap atau nomor yang bisa dihubungi kurir ya kak?',
        'Kak {nama}, kami masih menunggu kabar untuk paket {resi} yang belum sampai ({alasan}). Balas chat ini ya kak supaya kurir bisa mengantar ulang 😊',
        'Selamat siang kak {nama}, paket kakak lewat {kurir} masih tertahan ({alasan}). Kalau ada kendala atau perubahan alamat, kabari kami di sini ya kak 🙏',
    ];

    public const DEFAULT_RETURNING_TEXTS = [
        'Kak {nama}, paket {resi} sedang dikembalikan ke toko karena belum berhasil diantar ({alasan}). Mau kami kirim ulang, kak? Balas chat ini ya 🙏',
        'Halo kak {nama} 😊 Paket kakak ({kurir}, resi {resi}) dikembalikan kurir. Kalau kakak masih ingin pesanannya, kami bisa kirim ulang. Kabari kami ya kak.',
        'Kak {nama}, kami ingin memastikan pesanan kakak: dikirim ulang atau dibatalkan? Paket {resi} sedang kembali ke toko ({alasan}). Terima kasih kak 🙏',
    ];

    /**
     * @param  list<int>  $days
     * @param  list<string>  $texts
     * @param  list<string>  $returningTexts
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly int $hour,
        public readonly array $days,
        public readonly int $limit,
        public readonly array $texts,
        public readonly bool $returning,
        public readonly array $returningTexts,
        public readonly ?Carbon $enabledAt,
    ) {}

    public static function of(?Tenant $tenant): self
    {
        $s = (array) ($tenant?->parcel_follow_up ?? []);

        return new self(
            (bool) ($s['enabled'] ?? false),
            self::hour($s['hour'] ?? null),
            self::days($s['days'] ?? null),
            self::limit($s['limit'] ?? null),
            self::texts($s['texts'] ?? null, self::DEFAULT_TEXTS),
            (bool) ($s['returning'] ?? false),
            self::texts($s['returning_texts'] ?? null, self::DEFAULT_RETURNING_TEXTS),
            filled($s['enabled_at'] ?? null) ? Carbon::parse($s['enabled_at']) : null,
        );
    }

    /** Only shops ship parcels. */
    public static function appliesTo(?Tenant $tenant): bool
    {
        return $tenant?->industry()->key === 'retail';
    }

    /** The failures older than this do not take part (listed for a person instead). */
    public function cutoff(): Carbon
    {
        return ($this->enabledAt ?? now())->copy()->subDays(self::BACKLOG_DAYS);
    }

    /** @return list<string> texts for a cycle of $status */
    public function textsFor(string $status): array
    {
        return $status === ShipmentStatus::RETURNING ? $this->returningTexts : $this->texts;
    }

    /** @return array<string, mixed> for the settings form */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled, 'hour' => $this->hour, 'days' => array_map('strval', $this->days), 'limit' => $this->limit,
            'texts' => array_map(fn ($t) => ['text' => $t], $this->texts), 'returning' => $this->returning,
            'returning_texts' => array_map(fn ($t) => ['text' => $t], $this->returningTexts),
        ];
    }

    /**
     * What to store from the form. Switching on (from off) stamps enabled_at now; staying on keeps the first stamp.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function clean(array $state, ?Tenant $tenant): array
    {
        $before = self::of($tenant);
        $enabled = (bool) ($state['enabled'] ?? false);

        return [
            'enabled' => $enabled,
            'hour' => self::hour($state['hour'] ?? null),
            'days' => self::days($state['days'] ?? null),
            'limit' => self::limit($state['limit'] ?? null),
            'texts' => self::texts($state['texts'] ?? null, self::DEFAULT_TEXTS),
            'returning' => (bool) ($state['returning'] ?? false),
            'returning_texts' => self::texts($state['returning_texts'] ?? null, self::DEFAULT_RETURNING_TEXTS),
            'enabled_at' => $enabled ? ($before->enabled && $before->enabledAt ? $before->enabledAt : now())->toIso8601String() : null,
        ];
    }

    /** @return list<string> variables in $text this message does not know, as written */
    public static function unknown(string $text): array
    {
        preg_match_all('/\{\s*([a-z0-9_:]+)\s*\}/i', $text, $m);

        return array_values(array_unique(array_filter($m[1], fn ($v) => ! in_array(strtolower($v), self::VARIABLES, true))));
    }

    private static function hour(mixed $value): int
    {
        return is_numeric($value) ? max(self::MIN_HOUR, min(self::MAX_HOUR, (int) $value)) : self::DEFAULT_HOUR;
    }

    /** @return list<int> */
    private static function days(mixed $value): array
    {
        if (! is_array($value)) {
            return self::DEFAULT_DAYS;
        }

        $days = array_values(array_unique(array_filter(array_map('intval', $value), fn (int $d) => $d >= 1 && $d <= 7)));
        sort($days);

        return $days === [] ? self::DEFAULT_DAYS : $days;
    }

    private static function limit(mixed $value): int
    {
        return is_numeric($value) ? max(0, min(self::MAX_LIMIT, (int) $value)) : self::DEFAULT_LIMIT;
    }

    /**
     * @param  list<string>  $defaults
     * @return list<string> the filled texts, topped up from the defaults to MIN_TEXTS
     */
    private static function texts(mixed $value, array $defaults): array
    {
        $texts = array_values(array_filter(array_map(fn ($t) => trim((string) (is_array($t) ? ($t['text'] ?? '') : $t)), is_array($value) ? $value : []), fn ($t) => $t !== ''));

        foreach ($defaults as $default) {
            if (count($texts) >= self::MIN_TEXTS) {
                break;
            }

            if (! in_array($default, $texts, true)) {
                $texts[] = $default;
            }
        }

        return array_map(fn ($t) => mb_substr($t, 0, 1000), $texts);
    }
}
