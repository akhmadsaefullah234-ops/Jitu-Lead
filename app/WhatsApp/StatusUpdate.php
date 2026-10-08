<?php

namespace App\WhatsApp;

use App\Enums\MessageStatus;

final class StatusUpdate
{
    public function __construct(
        public readonly string $externalId,
        public readonly MessageStatus $status,
        public readonly ?string $error = null,
    ) {}
}
