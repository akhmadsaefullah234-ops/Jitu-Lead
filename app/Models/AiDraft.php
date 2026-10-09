<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_id', 'message_id', 'body', 'status', 'reason', 'api_call'])]
class AiDraft extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const DISCARDED = 'discarded';

    public const HANDOFF = 'handoff';

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WaConversation::class, 'conversation_id');
    }
}
