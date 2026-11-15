<?php

namespace App\Ai;

use App\Enums\AiMode;
use App\Enums\WaChannelType;
use App\Jobs\GenerateAiReplyJob;
use App\Jobs\ShowTyping;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaMessage;

/**
 * When an automatic reply is written: after the agency's short random pause (Arahan AI > Jeda balasan), with
 * "mengetik..." shown meanwhile on a QR gateway, so a reply does not land a second after the question. A client who
 * writes again during the pause gets one answer, to the newest message (GenerateAiReply skips older ones).
 * Draft mode is never paused: an agent is waiting for the suggestion.
 */
final class ReplyTiming
{
    public static function queue(Tenant $tenant, WaChannel $channel, WaMessage $message): void
    {
        $pause = $channel->ai_mode === AiMode::Auto ? AiSettings::of($tenant)->pause() : 0;
        $job = GenerateAiReplyJob::dispatch($tenant->getKey(), $message->getKey());

        if ($pause <= 0) {
            return;
        }

        $job->delay(now()->addSeconds($pause));

        $phone = $message->conversation?->phone;

        if (AiSettings::of($tenant)->typing() && $channel->type === WaChannelType::Gateway && filled($phone)) {
            ShowTyping::dispatch($tenant->getKey(), $channel->getKey(), (string) $phone);
        }
    }
}
