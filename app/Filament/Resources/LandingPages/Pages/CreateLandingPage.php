<?php

namespace App\Filament\Resources\LandingPages\Pages;

use App\Billing\Deny;
use App\Billing\PlanLimits;
use App\Filament\Resources\LandingPages\LandingPageResource;
use App\LandingPages\Templates;
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

    /** The chosen template fills in the sections, colour and font; the editor opens next. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $key = array_key_exists($data['template'] ?? '', Templates::options()) ? $data['template'] : Templates::BLANK;
        $template = Templates::get($key);

        return [
            'title' => $data['title'], 'slug' => $data['slug'], 'template' => $key, 'status' => 'draft',
            'color' => $template['color'], 'font' => $template['font'], 'description' => $template['meta'] ?: null,
            'blocks' => Templates::blocks($key),
        ];
    }

    protected function afterCreate(): void
    {
        EditLandingPage::ensureCaptureToken();
    }
}
