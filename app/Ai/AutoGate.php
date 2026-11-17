<?php

namespace App\Ai;

use App\Enums\AiMode;
use App\Models\AiKnowledgeItem;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\WaChannel;

/**
 * A NEW property agency's AI starts by preparing drafts: "AI membalas otomatis" waits until the agency is ready and an
 * admin presses "Naikkan ke Auto" (Mode AI). Agencies made before this, and every shop, are never gated. The checks
 * here are the minimum (a property and knowledge to answer from); the AI readiness score replaces them later.
 * Must run inside the tenant.
 */
final class AutoGate
{
    /** Whether Auto is still locked for this agency. */
    public static function locked(?Tenant $tenant): bool
    {
        return $tenant !== null && AiSettings::of($tenant)->autoGate();
    }

    /** @return list<string> what is still missing before "Naikkan ke Auto" can be pressed */
    public static function missing(): array
    {
        return array_values(array_filter([
            Property::query()->where('status', '!=', 'sold')->exists() ? null : 'Belum ada properti yang tersedia di Katalog properti.',
            AiKnowledgeItem::query()->where('active', true)->count() >= 3 ? null : 'Pengetahuan AI masih kurang dari 3 catatan aktif (harga, lokasi, cara bayar, jam survei).',
        ]));
    }

    /** The mode a reply really runs in: Auto counts as Draft while the gate is locked. */
    public static function effective(?AiMode $mode, ?Tenant $tenant): ?AiMode
    {
        return $mode === AiMode::Auto && self::locked($tenant) ? AiMode::Draft : $mode;
    }

    /**
     * Opens the gate: Auto may be chosen from now on, and every number that was preparing drafts now answers by itself.
     *
     * @return int the numbers switched to Auto
     */
    public static function open(Tenant $tenant): int
    {
        $tenant->forceFill(['ai_settings' => array_merge((array) $tenant->ai_settings, ['auto_gate' => false])])->save();

        return WaChannel::query()->where('ai_mode', AiMode::Draft->value)->update(['ai_mode' => AiMode::Auto->value]);
    }

    /** The modes an admin may pick now: Auto only once the gate is open. */
    public static function options(?Tenant $tenant): array
    {
        return collect(AiMode::cases())->reject(fn (AiMode $m) => $m === AiMode::Auto && self::locked($tenant))
            ->mapWithKeys(fn (AiMode $m) => [$m->value => $m->getLabel()])->all();
    }
}
