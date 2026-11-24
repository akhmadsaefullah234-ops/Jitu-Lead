<?php

namespace App\Filament\Support;

use App\Automation\DigitalStageAutomation;
use App\Models\Lead;
use App\Payments\DigitalCheckout;
use App\Shipping\ShippingQuote;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * "Pembayaran diterima" for a digital-product agency (Inbox and the lead page): a person checked the money. The lead
 * is marked paid and moves to "Lunas & akses terkirim"; the buyer's own access link (App\Digital\DigitalAccesses,
 * the same as after iPaymu) can be sent on WhatsApp. A shop's button (App\Automation\StageAutomation) is untouched.
 */
final class DigitalPaymentAction
{
    public static function make(Closure $lead, string $name = 'digitalPaymentReceived', ?Closure $after = null): Action
    {
        return Action::make($name)
            ->label('Pembayaran diterima')->icon(Heroicon::OutlinedBanknotes)->color('success')
            ->visible(fn () => ($l = $lead()) !== null && ! $l->trashed() && app(DigitalStageAutomation::class)->canConfirmPayment($l))
            ->badge(fn () => $lead()?->payment_proof_at ? 'bukti masuk' : null)
            ->modalHeading('Pembayaran sudah masuk?')
            ->modalDescription(fn () => self::description($lead()))
            ->modalSubmitActionLabel('Pembayaran diterima')
            ->fillForm(fn () => ['access_link' => $lead()?->access_link, 'send' => true])
            ->schema([
                TextInput::make('access_link')->label('Tautan akses (opsional)')->url()->maxLength(500)->live(onBlur: true)
                    ->placeholder('https://...')->helperText('Kosongkan bila produk punya berkas atau tautan di Katalog. Pembeli selalu menerima tautan akses khusus miliknya, bukan tautan ini.'),
                Toggle::make('send')->label('Kirim tautan akses ke pembeli lewat WhatsApp')
                    ->visible(fn (Get $get) => filled($get('access_link')) || (bool) $lead()?->property?->hasAccessFile() || filled($lead()?->property?->access_url)),
            ])
            ->action(function (array $data) use ($lead, $after) {
                $note = app(DigitalStageAutomation::class)->paymentReceived($lead(), auth()->user(), $data['access_link'] ?? null, (bool) ($data['send'] ?? false));
                $after && $after();
                Notification::make()->title('Pembayaran dicatat diterima')->body($note)->success()->send();
            });
    }

    /** "Kirim tautan bayar": a fresh (or the still open) iPaymu link for the lead's total, sent on WhatsApp. */
    public static function sendLink(Closure $lead, string $name = 'digitalPaymentLink'): Action
    {
        return Action::make($name)
            ->label('Kirim tautan bayar')->icon(Heroicon::OutlinedCreditCard)->color('gray')
            ->visible(fn () => ($l = $lead()) !== null && ! $l->trashed() && $l->paid_at === null && (int) $l->deal_value > 0 && DigitalCheckout::applies($l->tenant))
            ->requiresConfirmation()
            ->modalHeading('Kirim tautan bayar QRIS/VA?')
            ->modalDescription(fn () => 'Sistem membuat tautan bayar iPaymu sebesar '.ShippingQuote::rupiah((int) $lead()?->deal_value).' (atau memakai yang masih berlaku) dan mengirimnya ke pembeli lewat WhatsApp.')
            ->action(function () use ($lead) {
                $result = app(DigitalCheckout::class)->start($lead(), (int) $lead()->deal_value, auth()->user());
                $ok = in_array($result['status'], ['terkirim', 'dibuat'], true);
                Notification::make()->title($ok ? 'Tautan bayar dikirim' : 'Tautan bayar belum terkirim')->body($ok ? null : $result['pesan'])
                    ->{$ok ? 'success' : 'warning'}()->send();
            });
    }

    private static function description(?Lead $lead): string
    {
        $expected = $lead?->payment_amount !== null
            ? 'Nominal yang ditunggu: '.ShippingQuote::rupiah((int) $lead->payment_amount).($lead->payment_unique_code !== null ? ' (kode unik '.$lead->payment_unique_code.')' : '').'. '
            : '';

        return $expected.'Pastikan dana sudah ada di rekening. Lead pindah ke tahap Lunas & akses terkirim.';
    }
}
