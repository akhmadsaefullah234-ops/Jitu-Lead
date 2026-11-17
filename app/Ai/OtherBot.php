<?php

namespace App\Ai;

use App\Actions\GenerateAiReply;
use App\Models\WaConversation;
use App\Models\WaMessage;

/**
 * Two bots must never answer each other. A message from another business's automatic reply (an away message, a
 * menu, "pesan otomatis"), or a chat where the other side answers our automatic replies within seconds again and
 * again, is not a client: the AI keeps out of that chat for a day and an agent is told.
 */
final class OtherBot
{
    /** Hours the AI stays out of a chat that looks like another bot. */
    public const PAUSE_HOURS = 24;

    public const REASON = 'Tampaknya balasan otomatis dari bisnis lain, AI berhenti di chat ini 24 jam agar dua bot tidak saling membalas';

    private const AUTO_REPLY = [
        '/\b(?:pesan|balasan|jawaban)\s+otomatis\b/iu',
        '/\bauto[\s-]?(?:reply|respon(?:se|der)?|replay)\b/iu',
        '/\b(?:this\s+is\s+an?\s+)?automated\s+(?:message|reply|response)\b/iu',
        '/\b(?:terima\s*kasih|makasih)\s+(?:telah|sudah)\s+menghubungi\b/iu',
        '/\bthank(?:s|\s+you)\s+for\s+(?:contacting|reaching\s+out|your\s+message)\b/iu',
        '/\b(?:kami|we)\s+(?:sedang\s+)?(?:di\s+luar\s+jam\s+(?:kerja|operasional)|(?:are\s+)?(?:currently\s+)?(?:away|closed|unavailable))\b/iu',
        '/\b(?:ketik|balas|reply|pilih|tekan)\s+(?:angka\s+)?[0-9#*]\s+(?:untuk|to|for)\b/iu',
        '/\bsilakan\s+pilih\s+menu\b/iu',
    ];

    /** What our own automatic messages are marked with: the AI's replies, welcomes, follow-ups, the quiet-hours greeting. */
    private const AUTOMATIC = [GenerateAiReply::AI_ORIGIN, GenerateAiReply::QUIET_ORIGIN, 'welcome', 'follow_up'];

    /** The other side's reply within this many seconds of our automatic message counts as instant. */
    private const INSTANT_SECONDS = 3;

    /** An instant reply this long cannot be a person reading our message and typing back. */
    private const INSTANT_MIN_LENGTH = 15;

    /** Instant replies in a row, within the last 15 minutes, that make a ping-pong. */
    private const INSTANT_IN_A_ROW = 3;

    /** Why the AI should not answer this message, or null for a person writing. */
    public static function detect(WaConversation $conversation, WaMessage $inbound, string $text): ?string
    {
        foreach (self::AUTO_REPLY as $pattern) {
            if (preg_match($pattern, $text)) {
                return self::REASON;
            }
        }

        return self::pingPong($conversation, $inbound) ? self::REASON : null;
    }

    /**
     * The other side answered our last automatic messages within seconds, with more text than a person could read
     * and type in that time, several times in a row up to this message.
     */
    private static function pingPong(WaConversation $conversation, WaMessage $inbound): bool
    {
        $messages = $conversation->messages()->where('id', '<=', $inbound->getKey())->where('sent_at', '>=', now()->subMinutes(15))
            ->reorder()->orderByDesc('id')->limit(self::INSTANT_IN_A_ROW * 2)->get(['id', 'direction', 'origin', 'sent_at', 'body'])->reverse()->values();
        $instant = 0;

        foreach ($messages as $i => $message) {
            if ($message->direction !== 'in') {
                continue;
            }

            $before = $messages[$i - 1] ?? null;
            $fast = $before !== null && $before->direction === 'out' && in_array($before->origin, self::AUTOMATIC, true)
                && $message->sent_at !== null && $before->sent_at !== null
                && $before->sent_at->diffInSeconds($message->sent_at, true) <= self::INSTANT_SECONDS
                && mb_strlen(trim((string) $message->body)) >= self::INSTANT_MIN_LENGTH;
            $instant = $fast ? $instant + 1 : 0;
        }

        return $instant >= self::INSTANT_IN_A_ROW;
    }
}
