<?php

namespace App\Filament\Resources\LandingPages\Pages;

use App\Billing\Deny;
use App\Billing\PlanLimits;
use App\Filament\Resources\LandingPages\LandingPageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLandingPage extends CreateRecord
{
    protected static string $resource = LandingPageResource::class;

    protected function beforeCreate(): void
    {
        if ($denied = PlanLimits::current()?->denyAdding('landing_pages')) {
            Deny::notify($denied);
            $this->halt();
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return EditLandingPage::withPublishing($data);
    }

    protected function afterCreate(): void
    {
        EditLandingPage::ensureCaptureToken();
    }
}
