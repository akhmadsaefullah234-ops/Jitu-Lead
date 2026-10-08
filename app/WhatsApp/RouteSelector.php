<?php

namespace App\WhatsApp;

use App\Enums\WaChannelType;
use App\Models\WaChannel;
use App\Models\WaConversation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Decides which number a message goes out from (PRD F9):
 *
 *  1. Free 72-hour window after replying to an ad: official number.
 *  2. The client wrote in the last 24 hours: answer from the number they wrote to.
 *  3. Otherwise a gateway number starts the follow-up, at no Meta cost.
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
            );
        }

        if ($conversation->serviceWindowOpen() && $conversation->last_inbound_channel_id !== null) {
            $channel = $channels->firstWhere('id', $conversation->last_inbound_channel_id);

            if ($channel) {
                $left = $this->remaining($conversation->serviceWindowEndsAt());

                return $channel->isOfficial()
                    ? new RouteDecision(RouteKind::ReplyOfficial, $channel, 'Lewat nomor resmi, gratis', "Klien membalas dalam 24 jam terakhir, jadi pesan biasa gratis. Sisa jendela $left.")
                    : new RouteDecision(RouteKind::ReplyGateway, $channel, 'Balas di nomor gateway', "Klien terakhir menulis ke nomor gateway. Balasan dikirim dari nomor yang sama, sisa jendela $left.");
            }
        }

        if ($gateways->isNotEmpty()) {
            $channel = $gateways->first();
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

    private function remaining(?Carbon $until): string
    {
        if ($until === null || $until->isPast()) {
            return '0 menit';
        }

        $minutes = (int) now()->diffInMinutes($until);

        return $minutes >= 60 ? intdiv($minutes, 60).' jam '.($minutes % 60).' menit' : $minutes.' menit';
    }
}
