<?php

namespace App\Tracking;

use App\Support\PhoneNumber;

/**
 * What the ad platforms receive about a new lead. Contact details are hashed
 * with SHA-256 (lower-cased and trimmed, phone as digits only) before this
 * object exists, so neither the queue nor the logs ever hold them in clear.
 */
final class ConversionPayload
{
    /**
     * @param  array<string, string>  $ids  fbp, fbc, ttclid, ttp when the browser had them
     */
    public function __construct(
        public readonly string $eventId,
        public readonly int $eventTime,
        public readonly ?string $sourceUrl,
        public readonly ?string $emailHash,
        public readonly ?string $phoneHash,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly array $ids = [],
    ) {}

    /**
     * @param  array<string, string>  $ids
     */
    public static function make(string $eventId, ?string $email, ?string $phone, ?string $sourceUrl, ?string $ip, ?string $userAgent, array $ids = []): self
    {
        $phone = PhoneNumber::normalize($phone);

        return new self(
            $eventId,
            time(),
            $sourceUrl,
            filled($email) ? hash('sha256', mb_strtolower(trim($email))) : null,
            $phone === null ? null : hash('sha256', ltrim($phone, '+')),
            $ip,
            $userAgent,
            $ids,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self($data['eventId'], $data['eventTime'], $data['sourceUrl'], $data['emailHash'], $data['phoneHash'], $data['ip'], $data['userAgent'], $data['ids'] ?? []);
    }
}
