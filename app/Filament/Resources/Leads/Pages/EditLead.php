<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Pages\Inbox;
use App\Filament\Resources\Leads\LeadResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditLead extends EditRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('chat')->label('Chat WhatsApp')->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->visible(fn () => filled($this->record->phone) && ! $this->record->trashed())
                ->url(fn () => Inbox::getUrl(['lead' => $this->record->getKey()])),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
