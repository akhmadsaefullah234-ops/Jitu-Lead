<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One support conversation between a user of an agency and the platform owner's team. */
class SupportThread extends Model
{
    use BelongsToTenant;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_from_staff' => 'boolean', 'staff_unread' => 'boolean', 'user_unread' => 'boolean',
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }

    /** Adds a message and flags the thread as unread for the other side. */
    public function addMessage(string $body, bool $fromStaff, ?User $author): SupportMessage
    {
        $message = SupportMessage::create([
            'tenant_id' => $this->tenant_id, 'support_thread_id' => $this->getKey(),
            'user_id' => $author?->getKey(), 'from_staff' => $fromStaff, 'body' => $body,
        ]);

        $this->forceFill([
            'status' => self::OPEN, 'last_from_staff' => $fromStaff, 'last_message_at' => now(),
            'staff_unread' => ! $fromStaff, 'user_unread' => $fromStaff,
        ])->save();

        return $message;
    }
}
