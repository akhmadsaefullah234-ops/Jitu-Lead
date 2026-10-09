<?php

namespace App\Billing;

use App\Enums\StageType;
use App\Models\AiDraft;
use App\Models\FollowUpRule;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\Membership;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Support\CurrentTenant;

/**
 * The one place that answers "may this agency do that?". Reads the plan from
 * config/plans.php and the agency's subscription; nothing else decides limits.
 */
class PlanLimits
{
    public const KEYS = ['users', 'leads', 'wa', 'ai', 'followups', 'landing_pages'];

    private function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /** For the tenant of the current request or job; null when there is none (console). */
    public static function current(): ?self
    {
        $tenant = app(CurrentTenant::class)->get();

        return $tenant ? new self($tenant) : null;
    }

    /** True when the current agency's subscription is read-only; policies use this to refuse changes. */
    public static function readOnlyNow(): bool
    {
        return static::current()?->readOnly() ?? false;
    }

    public function subscription(): Subscription
    {
        return $this->tenant->currentSubscription();
    }

    /** @return array<string, mixed> */
    public function plan(): array
    {
        return config('plans.plans.'.$this->subscription()->effectivePlanKey());
    }

    public function readOnly(): bool
    {
        return $this->subscription()->isReadOnly();
    }

    public function feature(string $feature): bool
    {
        return (bool) ($this->plan()['features'][$feature] ?? false);
    }

    /** The limit including bought add-ons; null means unlimited. */
    public function limit(string $key): ?int
    {
        $base = $this->plan()['limits'][$key] ?? null;

        if ($base === null) {
            return null;
        }

        $sub = $this->subscription();

        return $base + match ($key) {
            'ai' => $sub->addon_ai * config('plans.addons.ai.unit'),
            'users' => $sub->addon_user * config('plans.addons.user.unit'),
            'wa' => $sub->addon_wa * config('plans.addons.wa.unit'),
            default => 0,
        };
    }

    public function usage(string $key): int
    {
        $id = $this->tenant->getKey();

        return match ($key) {
            'users' => Membership::query()->where('tenant_id', $id)->where('status', 'active')->count(),
            'leads' => Lead::withoutGlobalScope('tenant')->where('tenant_id', $id)
                ->whereHas('stage', fn ($q) => $q->withoutGlobalScope('tenant')->where('type', StageType::Open->value))->count(),
            'wa' => WaChannel::withoutGlobalScope('tenant')->where('tenant_id', $id)->count(),
            'ai' => AiDraft::withoutGlobalScope('tenant')->where('tenant_id', $id)->where('api_call', true)
                ->where('created_at', '>=', $this->subscription()->usageSince())->count(),
            'followups' => FollowUpRule::withoutGlobalScope('tenant')->where('tenant_id', $id)->count(),
            'landing_pages' => LandingPage::withoutGlobalScope('tenant')->where('tenant_id', $id)->count(),
            default => 0,
        };
    }

    /** Percent of the limit used, or null when unlimited. */
    public function percent(string $key): ?int
    {
        $limit = $this->limit($key);

        return $limit === null ? null : ($limit === 0 ? 100 : (int) min(100, floor($this->usage($key) / $limit * 100)));
    }

    public function atLimit(string $key): bool
    {
        $limit = $this->limit($key);

        return $limit !== null && $this->usage($key) >= $limit;
    }

    /**
     * Why something may not be added, or null when it may. Read-only agencies
     * can add nothing; otherwise the plan's limit decides.
     */
    public function denyAdding(string $key): ?string
    {
        if ($this->readOnly()) {
            return 'Langganan Anda sedang hanya-baca. Perpanjang paket di menu Langganan untuk menambah atau mengubah data.';
        }

        if (! $this->atLimit($key)) {
            return null;
        }

        $label = mb_strtolower(config("plans.limit_labels.$key"));

        return "Batas {$label} paket {$this->plan()['name']} sudah tercapai ({$this->limit($key)}). Naikkan paket atau beli tambahan di menu Langganan.";
    }

    public function denyFeature(string $feature): ?string
    {
        if ($this->feature($feature)) {
            return null;
        }

        return config("plans.feature_labels.$feature").' tidak termasuk paket '.$this->plan()['name'].'. Naikkan paket di menu Langganan.';
    }

    /** Whether the AI may answer another message, and if not, why. */
    public function denyAi(): ?string
    {
        if ($this->readOnly()) {
            return 'Langganan hanya-baca, AI berhenti.';
        }

        return $this->atLimit('ai') ? 'Kuota AI bulan ini habis' : null;
    }

    /**
     * Limits that need the admin's attention: 80% used or more. Leads and AI
     * keep working past 100%; the warning is all that happens.
     *
     * @return list<array{key: string, label: string, percent: int, usage: int, limit: int}>
     */
    public function warnings(): array
    {
        $out = [];

        foreach (self::KEYS as $key) {
            $limit = $this->limit($key);
            $percent = $this->percent($key);

            if ($limit !== null && $percent !== null && $percent >= 80) {
                $out[] = ['key' => $key, 'label' => config("plans.limit_labels.$key"), 'percent' => $percent, 'usage' => $this->usage($key), 'limit' => $limit];
            }
        }

        return $out;
    }
}
