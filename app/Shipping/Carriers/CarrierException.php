<?php

namespace App\Shipping\Carriers;

use RuntimeException;

/** A call to a courier aggregator that did not work, with a message an agent can act on (no credentials, no buyer data). */
class CarrierException extends RuntimeException
{
    public const BALANCE = 'balance';

    public const LOCATION = 'location';

    public const AUTH = 'auth';

    public const REJECTED = 'rejected';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(string $message, public readonly string $kind = self::REJECTED, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }

    /** Worth trying again later (the provider was down or slow), as opposed to a request it refused. */
    public function retryable(): bool
    {
        return $this->kind === self::UNAVAILABLE;
    }
}
