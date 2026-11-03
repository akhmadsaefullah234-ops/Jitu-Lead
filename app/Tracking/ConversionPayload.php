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
     * @param  array{meta?: ?string, tiktok?: ?string, value?: float, currency?: string, content_name?: string}  $event  the form's own
     *                                                                                                                   event per platform (null: send none) and its value; empty means the agency's default event
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
        public readonly array $event = [],
        public readonly array $extra = [],
    ) {}

    /** "website" for a form; "system_generated" for an event from the CRM (App\Tracking\PipelineConversions). */
    public function actionSource(): string
    {
        return $this->extra['action_source'] ?? 'website';
    }

    public function isTest(): bool
    {
        return (bool) ($this->extra['test'] ?? false);
    }

    /** The event a platform gets: the form's own choice, or the agency's default when the form has none. Null: send nothing. */
    public function eventName(string $platform, ?string $default): ?string
    {
        return array_key_exists($platform, $this->event) ? $this->event[$platform] : $default;
    }

    /** @return array<string, mixed> value, currency and content_name, when set */
    public function customData(): array
    {
        return array_filter([
            'value' => $this->event['value'] ?? null,
            'currency' => isset($this->event['value']) ? ($this->event['currency'] ?? PixelEvents::CURRENCY) : null,
            'content_name' => $this->event['content_name'] ?? null,
        ], fn ($v) => $v !== null) + (array) ($this->extra['custom'] ?? []);
    }

    /**
     * @param  array<string, string>  $ids
     */
    public static function make(string $eventId, ?string $email, ?string $phone, ?string $sourceUrl, ?string $ip, ?string $userAgent, array $ids = [], array $event = []): self
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
            $event,
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
        return new self($data['eventId'], $data['eventTime'], $data['sourceUrl'], $data['emailHash'], $data['phoneHash'], $data['ip'], $data['userAgent'], $data['ids'] ?? [], $data['event'] ?? [], $data['extra'] ?? []);
    }
}
