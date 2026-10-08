<?php

namespace App\WhatsApp;

use App\Models\WaChannel;

final class RouteDecision
{
    public function __construct(
        public readonly RouteKind $kind,
        public readonly ?WaChannel $channel,
        public readonly string $title,
        public readonly string $note,
        public readonly bool $needsIntro = false,
    ) {}

    public function isPaid(): bool
    {
        return $this->kind === RouteKind::PaidTemplate;
    }

    public function canSend(): bool
    {
        return $this->kind !== RouteKind::Unavailable;
    }

    public function isGateway(): bool
    {
        return in_array($this->kind, [RouteKind::ReplyGateway, RouteKind::FollowUpGateway], true);
    }

    /** Colour key used by the chat banner and bubbles. */
    public function tone(): string
    {
        return match (true) {
            $this->isPaid() => 'paid',
            $this->isGateway() => 'gateway',
            $this->kind === RouteKind::Unavailable => 'none',
            default => 'official',
        };
    }
}
