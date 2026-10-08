<?php

namespace App\WhatsApp;

final class SendResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $externalId = null,
        public readonly ?string $error = null,
        public readonly bool $channelProblem = false,
    ) {}

    public static function sent(?string $externalId): self
    {
        return new self(true, $externalId);
    }

    /**
     * @param  bool  $channelProblem  The number itself looks broken (unreachable, bad token), not just this message.
     */
    public static function failed(string $error, bool $channelProblem = false): self
    {
        return new self(false, null, $error, $channelProblem);
    }

    /**
     * Whether an HTTP status points at the number rather than at the message.
     */
    public static function statusIsChannelProblem(int $status): bool
    {
        return $status >= 500 || in_array($status, [401, 403, 429], true);
    }
}
