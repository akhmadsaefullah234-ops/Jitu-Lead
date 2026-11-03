<?php

namespace App\WhatsApp;

use App\Models\WaChannel;
use Carbon\CarbonInterface;

final class RouteDecision
{
    public function __construct(
        public readonly RouteKind $kind,
        public readonly ?WaChannel $channel,
        public readonly string $title,
        public readonly string $note,
        public readonly bool $needsIntro = false,
        /** When the free window this message rides on closes (free and official replies only). */
        public readonly ?CarbonInterface $until = null,
    ) {}

    /**
     * Something the agent should notice before sending: every window closed, a paid template, a message that will
     * start with the agency introduction, or no number at all. Shown as a warning line above the compose box.
     */
    public function needsAttention(): bool
    {
        return $this->needsIntro || in_array($this->kind, [RouteKind::FollowUpGateway, RouteKind::PaidTemplate, RouteKind::Unavailable], true);
    }

    /**
     * One short line for above the compose box, or null when there is nothing worth saying (a normal reply on the
     * gateway number, which has no 24-hour window).
     */
    public function summary(): ?string
    {
        return match ($this->kind) {
            RouteKind::ReplyGateway => null,
            RouteKind::ReplyOfficial => 'Nomor resmi · gratis'.$this->left(),
            RouteKind::FreeWindow => 'Nomor resmi · gratis (iklan)'.$this->left(),
            RouteKind::FollowUpGateway => $this->needsIntro ? 'Jendela tutup · diawali pesan perkenalan' : 'Jendela tutup · follow-up lewat nomor gateway',
            RouteKind::PaidTemplate => 'Jendela tutup · hanya template berbayar, tanpa lampiran',
            RouteKind::Unavailable => 'Belum ada nomor terhubung, pesan tidak bisa dikirim',
        };
    }

    private function left(): string
    {
        if ($this->until === null) {
            return '';
        }

        $minutes = max(0, (int) now()->diffInMinutes($this->until, false));

        return ' · sisa '.($minutes >= 60 ? intdiv($minutes, 60).' jam' : $minutes.' menit');
    }

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
