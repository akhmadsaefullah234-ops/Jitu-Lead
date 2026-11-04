<?php

namespace App\FollowUps;

use App\Models\Property;
use App\Models\Tenant;

/**
 * Whether a tenant's general follow-ups (rules without a product) are sent. "Otomatis": yes for a property
 * agency and for a shop with at most one active product; no for a shop with more, whose leads are followed up
 * per product, and leads without a product get the one "Tanya produk" message instead (AskProduct).
 * When they are not used the rules stay as they are; RunFollowUps only skips them. Runs inside the tenant.
 */
class GeneralFollowUps
{
    public const MODES = [
        'auto' => 'Otomatis',
        'always' => 'Selalu dipakai',
        'never' => 'Tidak dipakai',
    ];

    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    public function mode(): string
    {
        return isset(self::MODES[$this->tenant->general_follow_ups]) ? $this->tenant->general_follow_ups : 'auto';
    }

    public function applies(): bool
    {
        return match ($this->mode()) {
            'always' => true,
            'never' => false,
            default => ! $this->isShop() || self::activeProducts() <= 1,
        };
    }

    /** What is in force now and why, in one sentence for the Follow-up menu. */
    public function reason(): string
    {
        $item = $this->tenant->industry()->term('catalog_item');

        return match ($this->mode()) {
            'always' => 'Dipakai: dipilih "Selalu dipakai".',
            'never' => "Tidak dipakai: dipilih \"Tidak dipakai\". Atur follow-up di halaman tiap {$item}.",
            default => ! $this->isShop()
                ? 'Dipakai: otomatis untuk agensi properti.'
                : (($n = self::activeProducts()) <= 1
                    ? "Dipakai: toko retail dengan {$n} {$item} aktif."
                    : "Tidak dipakai: toko retail dengan {$n} {$item} aktif. Atur follow-up di halaman tiap {$item}."),
        };
    }

    /** Products that can still be sold (not "Habis" / "Terjual"). */
    public static function activeProducts(): int
    {
        return Property::query()->where('status', '!=', 'sold')->count();
    }

    private function isShop(): bool
    {
        return $this->tenant->industry()->key === 'retail';
    }
}
