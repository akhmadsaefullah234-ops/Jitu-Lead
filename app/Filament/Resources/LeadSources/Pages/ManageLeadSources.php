<?php

namespace App\Filament\Resources\LeadSources\Pages;

use App\Filament\Concerns\InSettingsAndTools;
use App\Filament\Resources\LeadSources\LeadSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLeadSources extends ManageRecords
{
    use InSettingsAndTools;

    protected static string $resource = LeadSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
