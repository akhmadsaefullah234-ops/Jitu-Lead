<?php

namespace App\WhatsApp;

use App\Enums\MessageStatus;
use App\Enums\WaChannelType;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Decides which number a message goes out from (PRD F9):
 *
 *  1. Free 72-hour window after replying to an ad: official number.
 *  2. The client wrote in the last 24 hours: answer from the number they wrote to.
 *  3. Otherwise a gateway number starts the follow-up, at no Meta cost: the one that already wrote to this
 *     client, else the lead owner's own number, else the first in the list.
 *  4. With no gateway connected, only a paid official template is left.
 */
class RouteSelector
{
    public function decide(WaConversation $conversation): RouteDecision
    {
        $channels = WaChannel::query()->connected()->orderBy('position')->orderBy('id')->get();
        $official = $channels->first(fn (WaChannel $c) => $c->type === WaChannelType::Official);
        $gateways = $channels->filter(fn (WaChannel $c) => $c->type === WaChannelType::Gateway);

        if ($official && $conversation->freeWindowOpen()) {
            return new RouteDecision(
                RouteKind::FreeWindow,
                $official,
                'Lewat nomor resmi, gratis',
                'Jendela gratis iklan Meta masih terbuka, sisa '.$this->remaining($conversation->free_window_ends_at).'. Semua pesan termasuk template gratis.',
                until: $conversation->free_window_ends_at,
            );
        }

        if ($conversation->serviceWindowOpen() && $conversation->last_inbound_channel_id !== null) {
            $channel = $channels->firstWhere('id', $conversation->last_inbound_channel_id);

            if ($channel) {
                $ends = $conversation->serviceWindowEndsAt();

                // A gateway number has no 24-hour window, so its reply says nothing about one.
                return $channel->isOfficial()
                    ? new RouteDecision(RouteKind::ReplyOfficial, $channel, 'Lewat nomor resmi, gratis', 'Klien membalas dalam 24 jam terakhir, jadi pesan biasa gratis. Sisa jendela '.$this->remaining($ends).'.', until: $ends)
                    : new RouteDecision(RouteKind::ReplyGateway, $channel, 'Balas di nomor gateway', 'Klien terakhir menulis ke nomor gateway. Balasan dikirim dari nomor yang sama.');
            }
        }

        if ($gateways->isNotEmpty()) {
            $channel = $this->preferredGateway($conversation, $gateways);
            $introduced = $conversation->messages()->where('channel_id', $channel->getKey())->exists();

            return new RouteDecision(
                RouteKind::FollowUpGateway,
                $channel,
                'Follow-up lewat nomor gateway',
                $introduced
                    ? 'Semua jendela gratis sudah tutup. Follow-up dikirim dari nomor gateway tanpa biaya Meta.'
                    : 'Semua jendela gratis sudah tutup. Pesan pertama dari nomor gateway diawali pesan perkenalan agensi.',
                needsIntro: ! $introduced,
            );
        }

        if ($official) {
            return new RouteDecision(
                RouteKind::PaidTemplate,
                $official,
                'Template berbayar di nomor resmi',
                'Jendela tutup dan gateway tidak terhubung. Hanya template yang bisa dikirim, dan agen harus mengonfirmasi biayanya.',
            );
        }

        return new RouteDecision(RouteKind::Unavailable, null, 'Belum ada nomor terhubung', 'Hubungkan nomor WhatsApp resmi atau gateway di pengaturan koneksi.');
    }

    /**
     * Connected gateway numbers in the order they should be tried, so a
     * follow-up can move to a spare number when one is down.
     *
     * @return Collection<int, WaChannel>
     */
    public function gatewayFallbacks(WaChannel $first): Collection
    {
        return WaChannel::query()->connected()->ofType(WaChannelType::Gateway)->orderBy('position')->orderBy('id')->get()
            ->sortBy(fn (WaChannel $c) => $c->is($first) ? 0 : 1)
            ->values();
    }

    /**
     * The gateway this client already heard from keeps the chat on one number (and needs no second intro);
     * otherwise the number of the agent who owns the lead (the WhatsApp rotation hands leads to agents by it).
     *
     * @param  Collection<int, WaChannel>  $gateways
     */
    private function preferredGateway(WaConversation $conversation, Collection $gateways): WaChannel
    {
        $last = $conversation->messages()->where('direction', 'out')->where('status', '!=', MessageStatus::Failed)
            ->whereIn('channel_id', $gateways->modelKeys())->latest('id')->value('channel_id');

        if ($last !== null) {
            return $gateways->firstWhere('id', $last);
        }

        $owner = PhoneNumber::whatsapp($conversation->lead?->owner?->whatsapp);

        return ($owner !== null ? $gateways->first(fn (WaChannel $c) => PhoneNumber::whatsapp($c->phone) === $owner) : null)
            ?? $gateways->first();
    }

    private function remaining(?Carbon $until): string
    {
        if ($until === null || $until->isPast()) {
            return '0 menit';
        }

        $minutes = (int) now()->diffInMinutes($until);

        return $minutes >= 60 ? intdiv($minutes, 60).' jam '.($minutes % 60).' menit' : $minutes.' menit';
    }
}
