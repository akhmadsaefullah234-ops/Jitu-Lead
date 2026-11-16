<?php

namespace App\Ai;

use RuntimeException;

/** The model's API refused a request; the message carries the API's reason ("AI error 400: invalid_request_error: ..."). */
class AiException extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $reason = '')
    {
        parent::__construct('AI error '.$status.($reason !== '' ? ': '.$reason : ''));
    }
}
