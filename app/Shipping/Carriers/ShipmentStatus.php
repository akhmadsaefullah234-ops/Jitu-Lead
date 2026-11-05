<?php

namespace App\Shipping\Carriers;

/**
 * Our own parcel statuses, whatever the provider calls them (config/carriers.php "status_map"). The rank orders
 * them, so a status that arrives after a later one (a slow callback) changes nothing.
 */
final class ShipmentStatus
{
    public const AWB_CREATED = 'awb_created';

    public const PICKED_UP = 'picked_up';

    public const IN_TRANSIT = 'in_transit';

    public const OUT_FOR_DELIVERY = 'out_for_delivery';

    public const DELIVERY_FAILED = 'delivery_failed';

    public const DELIVERED = 'delivered';

    public const RETURNING = 'returning';

    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';

    public const LABELS = [
        self::AWB_CREATED => 'Resi terbit',
        self::PICKED_UP => 'Diambil kurir',
        self::IN_TRANSIT => 'Dalam perjalanan',
        self::OUT_FOR_DELIVERY => 'Sedang diantar',
        self::DELIVERY_FAILED => 'Gagal diantar',
        self::DELIVERED => 'Diterima',
        self::RETURNING => 'Dikembalikan ke toko',
        self::RETURNED => 'Sudah kembali ke toko',
        self::CANCELLED => 'Dibatalkan',
    ];

    /** Out for delivery and a failed attempt share a rank: a courier tries again after failing. */
    private const RANK = [
        self::AWB_CREATED => 10, self::PICKED_UP => 20, self::IN_TRANSIT => 30, self::OUT_FOR_DELIVERY => 40, self::DELIVERY_FAILED => 40,
        self::DELIVERED => 60, self::RETURNING => 70, self::RETURNED => 80, self::CANCELLED => 90,
    ];

    public const FINAL = [self::DELIVERED, self::RETURNED, self::CANCELLED];

    /** Our status for a provider's code, or null when the code is not known. */
    public static function normalize(string $provider, ?string $code): ?string
    {
        $key = strtoupper(str_replace([' ', '-'], '_', trim((string) $code)));

        return $key === '' ? null : (config("carriers.{$provider}.status_map")[$key] ?? null);
    }

    public static function rank(?string $status): int
    {
        return self::RANK[$status] ?? 0;
    }

    /** True when $next may replace $current: later in the journey, or a new delivery attempt. */
    public static function advances(?string $current, string $next): bool
    {
        if (in_array($current, self::FINAL, true)) {
            return false;
        }

        return self::rank($next) > self::rank($current) || ($next !== $current && self::rank($next) === self::rank($current));
    }

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? 'Belum ada status';
    }
}
