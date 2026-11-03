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

    /** Ad fields a referral may carry and how long each may be. */
    public const REFERRAL_FIELDS = ['source_id' => 100, 'source_type' => 40, 'source_url' => 500, 'headline' => 255, 'body' => 1000, 'ctwa_clid' => 255, 'thumbnail_url' => 1000];

    /**
     * Click-to-WhatsApp ad data from either provider, reduced to the known fields as trimmed text. Null when
     * nothing that identifies an ad is left.
     *
     * @return array<string, string>|null
     */
    public static function referral(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $out = [];
        foreach (self::REFERRAL_FIELDS as $key => $max) {
            $value = $raw[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $out[$key] = mb_substr(trim((string) $value), 0, $max);
            }
        }

        return array_intersect_key($out, array_flip(['source_id', 'headline', 'source_url', 'ctwa_clid'])) !== [] ? $out : null;
    }

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
