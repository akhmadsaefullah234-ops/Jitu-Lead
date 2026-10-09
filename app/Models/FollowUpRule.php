<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'stage_id', 'delay_hours', 'body', 'send_from_hour', 'send_until_hour', 'active'])]
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

    /** Whether the given hour (0-23) falls inside the rule's sending hours. */
    public function allowsHour(int $hour): bool
    {
        return $hour >= $this->send_from_hour && $hour < $this->send_until_hour;
    }
}
