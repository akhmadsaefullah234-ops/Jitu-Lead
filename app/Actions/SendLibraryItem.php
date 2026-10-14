<?php

namespace App\Actions;

use App\Models\MediaAsset;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\WhatsApp\WhatsAppException;
use Illuminate\Support\Facades\Storage;

/** Sends one media library item to a customer: a file as a picture or document, a link as text. */
class SendLibraryItem
{
    public function __construct(private SendWhatsAppMessage $send) {}

    public function __invoke(WaConversation $conversation, User $actor, MediaAsset $asset, ?string $caption = null): WaMessage
    {
        if ($asset->isLink()) {
            return ($this->send)($conversation, $actor, trim(($caption ? $caption."\n\n" : '').$asset->linkText()));
        }

        if (blank($asset->path) || ! Storage::disk('local')->exists($asset->path)) {
            throw new WhatsAppException("File \"{$asset->title}\" tidak ditemukan di pustaka. Unggah ulang.");
        }

        return $this->send->sendFile($conversation, $actor, $asset->path, (string) $asset->original_name, $caption ?? $asset->title);
    }
}
