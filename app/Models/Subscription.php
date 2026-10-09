<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An agency's plan and billing state. One row per tenant. Not tenant-scoped on
 * purpose: the platform owner's commands read and change it for any agency.
 */
class Subscription extends Model
{
    public const TRIAL = 'trial';

    public const ACTIVE = 'active';

    public const PAST_DUE = 'past_due';

    public const READ_ONLY = 'read_only';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
            'reminder_sent_for' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** The plan whose limits apply: trials get the trial plan's limits. */
    public function effectivePlanKey(): string
    {
        $key = $this->status === self::TRIAL ? null : $this->plan;

        return array_key_exists((string) $key, config('plans.plans')) ? $key : config('plans.trial_plan');
    }

    /** When the trial or paid period ends, or null if it never does (nothing set). */
    public function endsAt(): ?Carbon
    {
        return $this->status === self::TRIAL ? $this->trial_ends_at : $this->current_period_end;
    }

    public function isReadOnly(): bool
    {
        return $this->status === self::READ_ONLY;
    }

    /** Whole days left, never negative; null without an end date. */
    public function daysLeft(): ?int
    {
        $end = $this->endsAt();

        return $end === null ? null : max(0, (int) ceil(now()->diffInSeconds($end, false) / 86400));
    }

    /** Start of the period AI usage is counted from. */
    public function usageSince(): Carbon
    {
        return $this->current_period_start ?? now()->startOfMonth();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::TRIAL => 'Masa percobaan',
            self::ACTIVE => 'Aktif',
            self::PAST_DUE => 'Jatuh tempo (masa tenggang)',
            self::READ_ONLY => 'Hanya-baca',
            default => $this->status,
        };
    }
}
