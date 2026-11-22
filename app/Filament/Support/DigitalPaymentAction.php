<?php

namespace App\Filament\Support;

use App\Automation\DigitalStageAutomation;
use App\Models\Lead;
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
 * is marked paid and moves to "Lunas & akses terkirim"; the access link, when given, is kept on the lead and can be
 * sent to the buyer on WhatsApp. A shop's button (App\Automation\StageAutomation) is untouched.
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
                    ->placeholder('https://...')->helperText('Tautan unduhan, undangan grup, atau halaman login produk. Disimpan di lead.'),
                Toggle::make('send')->label('Kirim tautan akses ke pembeli lewat WhatsApp')
                    ->visible(fn (Get $get) => filled($get('access_link'))),
            ])
            ->action(function (array $data) use ($lead, $after) {
                $note = app(DigitalStageAutomation::class)->paymentReceived($lead(), auth()->user(), $data['access_link'] ?? null, (bool) ($data['send'] ?? false));
                $after && $after();
                Notification::make()->title('Pembayaran dicatat diterima')->body($note)->success()->send();
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
