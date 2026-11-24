<?php

namespace App\Filament\Support;

use App\Digital\DigitalAccesses;
use App\Models\DigitalAccess;
use App\Models\Lead;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * The buyer's access on the lead page and in the Inbox (App\Digital\DigitalAccesses): send the same link again (staff
 * at most three times per lead, an admin any time), "Cabut akses", and an admin's new link. Only for a digital agency.
 */
final class DigitalAccessActions
{
    public static function resend(Closure $lead, string $name = 'digitalAccessResend'): Action
    {
        return Action::make($name)
            ->label('Kirim ulang akses')->icon(Heroicon::OutlinedPaperAirplane)->color('gray')
            ->visible(fn () => self::shown($lead()))
            ->disabled(fn () => app(DigitalAccesses::class)->cannotResend($lead(), auth()->user()) !== null)
            ->tooltip(fn () => app(DigitalAccesses::class)->cannotResend($lead(), auth()->user()))
            ->requiresConfirmation()
            ->modalHeading('Kirim ulang tautan akses?')
            ->modalDescription(fn () => 'Tautan yang sama dikirim lagi ke pembeli lewat WhatsApp. Sudah dikirim ulang '
                .app(DigitalAccesses::class)->resendsUsed($lead()).' kali; tanpa admin paling banyak '.DigitalAccesses::MAX_RESENDS.' kali.')
            ->action(function () use ($lead) {
                $result = app(DigitalAccesses::class)->resend($lead(), auth()->user(), auth()->user()->name);
                Notification::make()->title($result['ok'] ? 'Tautan akses dikirim ulang' : 'Tautan akses belum terkirim')->body($result['ok'] ? null : $result['pesan'])
                    ->{$result['ok'] ? 'success' : 'warning'}()->send();
            });
    }

    public static function reissue(Closure $lead, string $name = 'digitalAccessReissue'): Action
    {
        return Action::make($name)
            ->label('Tautan akses baru')->icon(Heroicon::OutlinedArrowPath)->color('gray')
            ->visible(fn () => self::shown($lead()) && DigitalAccesses::isAdmin(auth()->user()) && ! app(DigitalAccesses::class)->isRefunded($lead()))
            ->requiresConfirmation()
            ->modalHeading('Terbitkan tautan akses baru?')
            ->modalDescription('Khusus admin. Tautan lama berhenti berlaku, pembeli menerima tautan baru dengan masa berlaku dan batas unduhan baru.')
            ->action(function () use ($lead) {
                $result = app(DigitalAccesses::class)->reissue($lead(), auth()->user());
                Notification::make()->title($result['ok'] ? 'Tautan akses baru dikirim' : 'Tautan akses baru belum terkirim')->body($result['ok'] ? null : $result['pesan'])
                    ->{$result['ok'] ? 'success' : 'warning'}()->send();
            });
    }

    public static function revoke(Closure $lead, string $name = 'digitalAccessRevoke'): Action
    {
        return Action::make($name)
            ->label('Cabut akses')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
            ->visible(fn () => self::shown($lead()) && app(DigitalAccesses::class)->active($lead()) !== null)
            ->requiresConfirmation()
            ->modalHeading('Cabut akses pembeli?')
            ->modalDescription('Semua tautan akses lead ini berhenti berlaku dan tidak bisa dibuka lagi. Dicatat di aktivitas lead.')
            ->action(function () use ($lead) {
                app(DigitalAccesses::class)->revoke($lead(), auth()->user(), 'dicabut manual');
                Notification::make()->title('Akses dicabut')->success()->send();
            });
    }

    /** The lead page's summary: each link, its state, downloads, and the last IP and browser. */
    public static function summary(?Lead $lead): HtmlString
    {
        $rows = $lead ? DigitalAccess::query()->where('lead_id', $lead->getKey())->latest('id')->limit(5)->get() : collect();

        if ($rows->isEmpty()) {
            return new HtmlString('<span style="opacity:.7">Belum ada tautan akses. Dibuat saat pembayaran diterima.</span>');
        }

        $lines = $rows->map(fn (DigitalAccess $a) => '<li><strong>'.e($a->problem() ? ucfirst($a->problem()).($a->revoked_reason ? ' ('.$a->revoked_reason.')' : '') : 'Aktif').'</strong>'
            .' · berlaku sampai '.e(DigitalAccesses::date($a->expires_at))
            .' · unduhan '.$a->downloads.'/'.$a->max_downloads
            .' · '.($a->opened_at ? 'dibuka '.e($a->opened_at->timezone($lead->tenant?->timezone ?: 'Asia/Jakarta')->format('j M Y H:i')) : 'belum dibuka')
            .($a->resends > 0 ? ' · dikirim ulang '.$a->resends.'x' : '')
            .($a->last_ip ? '<br><span style="opacity:.7">Terakhir: '.e($a->last_ip).' · '.e($a->last_user_agent).'</span>' : '').'</li>');

        return new HtmlString('<ul style="margin:0;padding-left:1.1rem;display:grid;gap:.4rem">'.$lines->implode('').'</ul>'
            .'<p style="margin:.5rem 0 0;opacity:.7">Kirim ulang: '.app(DigitalAccesses::class)->resendsUsed($lead).' dari '.DigitalAccesses::MAX_RESENDS.' tanpa admin.</p>');
    }

    private static function shown(?Lead $lead): bool
    {
        return $lead !== null && ! $lead->trashed() && $lead->paid_at !== null && app(DigitalAccesses::class)->applies($lead)
            && DigitalAccess::query()->where('lead_id', $lead->getKey())->exists();
    }
}
