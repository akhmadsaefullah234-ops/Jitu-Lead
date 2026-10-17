<?php

namespace App\WhatsApp;

use Carbon\CarbonImmutable;

/**
 * A message in a chat with a client, normalized from either provider's payload: usually from the client,
 * or ($fromMe) a reply the business typed in the WhatsApp app on the phone.
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
        // Sent by the business itself from the WhatsApp app on the phone; $from is then the customer.
        public readonly bool $fromMe = false,
    ) {}

    /**
     * A provider's epoch timestamp as a moment in the application's timezone.
     * Carbon::createFromTimestamp() returns UTC, and the database stores the wall
     * clock of whatever zone the value carries, so it has to be converted first.
     */
    public static function at(int|string|null $timestamp): CarbonImmutable
    {
        $epoch = (int) $timestamp;

        return CarbonImmutable::createFromTimestamp($epoch > 0 ? $epoch : time())->setTimezone(config('app.timezone'));
    }
}
