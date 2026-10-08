<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

#[Fillable(['lead_id', 'phone', 'last_inbound_at', 'last_inbound_channel_id', 'ad_entry_at', 'free_window_ends_at', 'ad_data', 'unread_count', 'last_message_at'])]
class WaConversation extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::saving(function (WaConversation $conversation) {
            if ($conversation->isDirty('lead_id') && ! Lead::withoutGlobalScopes()->whereKey($conversation->lead_id)->where('tenant_id', $conversation->tenant_id ?? app(CurrentTenant::class)->id())->exists()) {
                throw new LogicException('Conversation lead must belong to the same tenant.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'last_inbound_at' => 'datetime',
            'ad_entry_at' => 'datetime',
            'free_window_ends_at' => 'datetime',
            'last_message_at' => 'datetime',
            'ad_data' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function lastInboundChannel(): BelongsTo
    {
        return $this->belongsTo(WaChannel::class, 'last_inbound_channel_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WaMessage::class, 'conversation_id')->orderBy('sent_at')->orderBy('id');
    }

    /** The 72-hour window of free messages that follows a reply to a Click-to-WhatsApp ad. */
    public function freeWindowOpen(): bool
    {
        return $this->free_window_ends_at !== null && $this->free_window_ends_at->isFuture();
    }

    /** The 24-hour window in which ordinary messages are free after the client writes. */
    public function serviceWindowEndsAt(): ?Carbon
    {
        return $this->last_inbound_at?->copy()->addDay();
    }

    public function serviceWindowOpen(): bool
    {
        return $this->serviceWindowEndsAt()?->isFuture() ?? false;
    }
}
