<?php

namespace App\Models;

use App\Enums\Interest;
use App\Enums\Need;
use App\Enums\PaymentMethod;
use App\Enums\StageType;
use App\Models\Concerns\BelongsToTenant;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\Tracking\PipelineConversions;
use App\WhatsApp\BusinessLabels;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

#[Fillable([
    'name', 'phone', 'email', 'lead_source_id', 'stage_id', 'owner_id', 'interest',
    'next_action', 'next_action_due_at', 'need', 'property_type', 'location',
    'budget_min', 'budget_max', 'payment_method', 'property_id', 'survey_at',
    'survey_location', 'unit', 'deal_value', 'lost_reason_id', 'custom_fields', 'stage_entered_at',
    'variant', 'quantity', 'shipping_address', 'courier', 'shipping_cost', 'order_items',
    'payment_type', 'payment_type_source', 'payment_proof_at', 'paid_at', 'shipping_province', 'tracking_number', 'shipped_at', 'shipping_address_parts',
    'ad_id', 'ad_title', 'ad_url', 'product_source',
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

    /** Set for a save whose way of paying was read from the chat (kept unconfirmed); any other change confirms it. */
    public bool $paymentFromChat = false;

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

            // A way of paying set by anyone but the chat reader (an agent, the order form, a confirmed order) is confirmed.
            if ($lead->isDirty('payment_type') && ! $lead->isDirty('payment_type_source') && ! $lead->paymentFromChat) {
                $lead->payment_type_source = null;
            }

            $lead->paymentFromChat = false;

            // The clock for "no response yet" starts whenever someone new is given the lead.
            if ($lead->isDirty('owner_id') && ! $lead->isDirty('assigned_at')) {
                $lead->assigned_at = $lead->owner_id === null ? null : now();
            }
        });

        // A stage the agency mapped to an ad conversion, or becoming Hot, is reported to Meta/TikTok.
        static::saved(fn (Lead $lead) => PipelineConversions::saved($lead));
        // The same moments put WhatsApp Business labels on the chat (trial, off by default).
        static::saved(fn (Lead $lead) => BusinessLabels::saved($lead));
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
            'assigned_at' => 'datetime',
            'custom_fields' => 'array',
            'order_items' => 'array',
            'shipping_address_parts' => 'array',
            'payment_proof_at' => 'datetime',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
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

    /**
     * Open leads whose owner has not reacted for the given minutes since getting them: no message, note
     * or stage move by the owner, and no AI reply or reply from the WhatsApp app on the phone either. Only leads that were given to someone count.
     */
    public function scopeUnattended(Builder $query, int $minutes): void
    {
        $query->whereNotNull('owner_id')
            ->whereNull('deleted_at')
            ->where('assigned_at', '<=', now()->subMinutes($minutes))
            ->whereHas('stage', fn (Builder $q) => $q->where('type', StageType::Open->value))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('lead_activities as la')
                ->whereColumn('la.lead_id', 'leads.id')
                ->whereColumn('la.created_at', '>=', 'leads.assigned_at')
                ->where('la.type', '!=', 'reassigned')
                ->where(fn ($w) => $w->whereColumn('la.user_id', 'leads.owner_id')->orWhereIn('la.type', ['ai_reply', 'whatsapp_out_phone'])));
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

    public function conversation(): HasOne
    {
        return $this->hasOne(WaConversation::class);
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

    /** @return list<string> why the form submission that made this lead looked like a robot (App\LeadForms\SpamGuard); empty when it did not */
    public function spamReasons(): array
    {
        return array_values((array) (((array) $this->custom_fields)['spam']['reasons'] ?? []));
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
