<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Automation\StageAutomation;
use App\Filament\Concerns\NotifiesShipmentFormErrors;
use App\Filament\Pages\Inbox;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Support\ShipmentActions;
use App\Models\Shipment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditLead extends EditRecord
{
    use NotifiesShipmentFormErrors;

    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('paymentReceived')->label('Pembayaran diterima')->icon(Heroicon::OutlinedBanknotes)->color('success')
                ->visible(fn () => ! $this->record->trashed() && app(StageAutomation::class)->canConfirmPayment($this->record))
                ->badge(fn () => $this->record->payment_proof_at ? 'bukti masuk' : null)
                ->requiresConfirmation()
                ->modalHeading('Pembayaran sudah masuk?')
                ->modalDescription('Pastikan dana sudah ada di rekening. Lead pindah ke tahap Dikemas & dikirim.')
                ->action(function () {
                    app(StageAutomation::class)->paymentReceived($this->record, auth()->user());
                    $this->refreshFormData(['next_action', 'next_action_due_at']);
                    Notification::make()->title('Pembayaran dicatat diterima')->success()->send();
                }),
            Action::make('chat')->label('Chat WhatsApp')->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->visible(fn () => filled($this->record->phone) && ! $this->record->trashed())
                ->url(fn () => Inbox::getUrl(['lead' => $this->record->getKey()])),
            ShipmentActions::cancelAuto(fn () => $this->record),
            ActionGroup::make([
                ShipmentActions::create(fn () => $this->record),
                ShipmentActions::retry(fn () => $this->record),
                ShipmentActions::link(fn () => $this->record),
                ShipmentActions::track(fn () => $this->record),
                ShipmentActions::label(fn () => $this->record),
                ShipmentActions::cancel(fn () => $this->record),
            ])->label('Pengiriman')->icon(Heroicon::OutlinedTruck)->button()->color('gray')->visible(fn () => ShipmentActions::available() && ! $this->record->trashed())
                ->badge(fn () => Shipment::query()->tab('unprinted')->where('lead_id', $this->record->getKey())->exists() ? 'Belum dicetak' : null)->badgeColor('warning'),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /** A product an agent picks here is theirs: a later ad click does not change it, and the AI follows it. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('property_id', $data) && (int) $data['property_id'] !== (int) $this->record->property_id) {
            $data['product_source'] = filled($data['property_id']) ? 'agent' : null;
        }

        return $data;
    }

    /** A tracking number typed here goes to the buyer and moves the lead (Pengaturan > Otomatisasi tahap). */
    protected function afterSave(): void
    {
        if ($this->record->wasChanged('tracking_number') && filled($this->record->tracking_number)) {
            $result = app(StageAutomation::class)->shipped($this->record, $this->record->courier, $this->record->tracking_number, auth()->user());
            Notification::make()->title($result)->{str_contains($result, 'belum') || str_contains($result, 'gagal') ? 'warning' : 'success'}()->send();
        }
    }
}
