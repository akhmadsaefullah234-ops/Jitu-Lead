<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'stage_id', 'delay_days', 'send_time', 'body', 'active'])]
class FollowUpRule extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (FollowUpRule $rule) {
            if ($rule->stage_id !== null && $rule->isDirty('stage_id')
                && ! Stage::withoutGlobalScopes()->whereKey($rule->stage_id)->where('tenant_id', $rule->tenant_id ?? app(CurrentTenant::class)->id())->exists()) {
                throw new \LogicException('Rule stage must belong to the rule\'s tenant.');
            }
        });
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(FollowUpLog::class);
    }

    /**
     * The moment this rule falls due for a lead that entered its stage at the
     * given time: N calendar days later (in the agency's timezone) at the
     * rule's sending time, so H+1 at 09:00 means 09:00 the next day.
     */
    public function dueAt(CarbonInterface $enteredAt, string $timezone): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $this->send_time));

        return CarbonImmutable::instance($enteredAt)->setTimezone($timezone)
            ->startOfDay()->addDays($this->delay_days)->setTime($hour, $minute);
    }
}
