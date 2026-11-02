<?php

namespace App\Filament\Resources\LeadForms\Pages;

use App\Filament\Resources\LeadForms\LeadFormResource;
use App\LeadForms\FormTemplates;
use App\Support\Industry;
use Filament\Resources\Pages\CreateRecord;

class CreateLeadForm extends CreateRecord
{
    protected static string $resource = LeadFormResource::class;

    /** The chosen template fills in fields, look and the after-sending step; the editor opens next. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ['name' => $data['name'], 'slug' => $data['slug'], 'status' => 'active', 'is_primary' => false]
            + FormTemplates::attributes(array_key_exists($data['template'] ?? '', FormTemplates::options(Industry::current()->key)) ? $data['template'] : FormTemplates::BLANK);
    }
}
