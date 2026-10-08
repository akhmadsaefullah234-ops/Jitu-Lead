<?php

namespace App\Models;

use App\Enums\MessageStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_id', 'channel_id', 'user_id', 'direction', 'type', 'body', 'media', 'template_name', 'is_intro', 'is_paid', 'status', 'external_id', 'error', 'sent_at'])]
class WaMessage extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => MessageStatus::class,
            'media' => 'array',
            'is_intro' => 'boolean',
            'is_paid' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WaConversation::class, 'conversation_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(WaChannel::class, 'channel_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isInbound(): bool
    {
        return $this->direction === 'in';
    }
}
