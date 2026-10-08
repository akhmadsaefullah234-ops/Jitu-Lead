<?php

namespace App\Filament\Resources\LostReasons\Pages;

use App\Filament\Resources\LostReasons\LostReasonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLostReasons extends ManageRecords
{
    protected static string $resource = LostReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
