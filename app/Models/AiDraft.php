<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

#[Fillable(['conversation_id', 'message_id', 'body', 'status', 'reason', 'api_call', 'media_asset_ids'])]
class AiDraft extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const DISCARDED = 'discarded';

    public const HANDOFF = 'handoff';

    protected function casts(): array
    {
        return ['media_asset_ids' => 'array'];
    }

    /** The library items the AI suggests sending with this answer (only those still sendable). */
    /** @return Collection<int, MediaAsset> */
    public function suggestedAssets(): Collection
    {
        return $this->media_asset_ids ? MediaAsset::query()->where('active', true)->whereIn('id', $this->media_asset_ids)->get() : collect();
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WaConversation::class, 'conversation_id');
    }
}
