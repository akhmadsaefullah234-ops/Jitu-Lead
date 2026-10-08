<?php

namespace App\WhatsApp;

use Carbon\CarbonImmutable;

/**
 * A message from a client, normalized from either provider's payload.
 */
final class InboundMessage
{
    /**
     * @param  array<string, mixed>|null  $media
     * @param  array<string, mixed>|null  $referral  Click-to-WhatsApp ad data (ad id, headline, source URL, click id)
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $from,
        public readonly string $type,
        public readonly ?string $body,
        public readonly ?array $media,
        public readonly CarbonImmutable $sentAt,
        public readonly ?string $contactName = null,
        public readonly ?array $referral = null,
    ) {}
}
