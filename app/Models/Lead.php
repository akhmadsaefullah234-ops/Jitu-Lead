<?php

namespace App\Models;

use App\Enums\Interest;
use App\Enums\Need;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToTenant;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

#[Fillable([
    'name', 'phone', 'email', 'lead_source_id', 'stage_id', 'owner_id', 'interest',
    'next_action', 'next_action_due_at', 'need', 'property_type', 'location',
    'budget_min', 'budget_max', 'payment_method', 'property_id', 'survey_at',
    'survey_location', 'unit', 'deal_value', 'lost_reason_id', 'custom_fields', 'stage_entered_at',
])]
class Lead extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * Foreign keys that must point at records of the lead's own tenant.
     */
    private const TENANT_RELATIONS = [
        'stage_id' => Stage::class,
        'lead_source_id' => LeadSource::class,
        'property_id' => Property::class,
        'lost_reason_id' => LostReason::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (Lead $lead) {
            // Saving runs before the tenant is stamped on create, so fall back to the current tenant.
            $tenantId = $lead->tenant_id ?? app(CurrentTenant::class)->id();

            foreach (self::TENANT_RELATIONS as $column => $model) {
                if ($lead->isDirty($column) && $lead->{$column} !== null
                    && ! $model::withoutGlobalScopes()->whereKey($lead->{$column})->where('tenant_id', $tenantId)->exists()) {
                    throw new LogicException("Lead {$column} must belong to the lead's tenant.");
                }
            }

            if ($lead->isDirty('owner_id') && $lead->owner_id !== null
                && ! Membership::query()->where('tenant_id', $tenantId)->where('user_id', $lead->owner_id)->exists()) {
                throw new LogicException("Lead owner must be a member of the lead's tenant.");
            }
        });
    }

    protected function casts(): array
    {
        return [
            'interest' => Interest::class,
            'need' => Need::class,
            'payment_method' => PaymentMethod::class,
            'next_action_due_at' => 'datetime',
            'survey_at' => 'datetime',
            'stage_entered_at' => 'datetime',
            'custom_fields' => 'array',
        ];
    }

    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => PhoneNumber::normalize($value));
    }

    protected function email(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function lostReason(): BelongsTo
    {
        return $this->belongsTo(LostReason::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest();
    }

    /** When the lead arrived in its current stage; follow-up delays count from here. */
    public function stageEnteredAt(): Carbon
    {
        return $this->stage_entered_at ?? $this->created_at;
    }

    public function isOverdue(): bool
    {
        return $this->next_action_due_at !== null && $this->next_action_due_at->isPast();
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereHas('stage', fn (Builder $stage) => $stage->where('type', 'open'));
    }

    public function scopeOverdue(Builder $query): void
    {
        $query->open()->where('next_action_due_at', '<', now());
    }

    /**
     * Other leads in the same tenant with the same phone or email.
     */
    public function duplicates(): Builder
    {
        return static::query()
            ->whereKeyNot($this->getKey())
            ->where(function (Builder $query) {
                $query->when($this->phone, fn (Builder $q) => $q->orWhere('phone', $this->phone))
                    ->when($this->email, fn (Builder $q) => $q->orWhere('email', $this->email));
            })
            ->when(! $this->phone && ! $this->email, fn (Builder $q) => $q->whereRaw('1 = 0'));
    }
}
