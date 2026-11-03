<?php

namespace App\Filament\Resources\LeadForms\Pages;

use App\Filament\Resources\LeadForms\LeadFormResource;
use App\LeadForms\FormFields;
use App\LeadForms\FormView;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Throwable;

class EditLeadForm extends EditRecord
{
    protected static string $resource = LeadFormResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('visit')->label('Buka formulir')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                ->url(fn () => $this->record->isActive() ? $this->record->publicUrl() : LeadFormResource::previewUrl($this->record), shouldOpenInNewTab: true),
            DeleteAction::make(),
        ];
    }

    /** Address sub-fields and payment choices are edited as lists of their own. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['fields'] = FormFields::forEditor($data['fields'] ?? []);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['fields'] = FormFields::normalize($data['fields'] ?? []);

        return $data;
    }

    /**
     * The form as it is in the editor right now, drawn like the public page, for the side preview. Nothing is
     * saved and no scripts run inside it; a picture picked but not yet saved only shows after Simpan.
     */
    public function previewHtml(): string
    {
        try {
            $state = $this->form->getRawState();
            $form = $this->record->replicate();
            $form->forceFill([
                'name' => $state['name'] ?? $this->record->name,
                'design' => array_replace((array) $this->record->design, array_filter((array) ($state['design'] ?? []), 'is_string')),
                'fields' => FormFields::normalize($state['fields'] ?? []),
                'submit' => (array) ($state['submit'] ?? []),
                'buttons' => array_values((array) ($state['buttons'] ?? [])),
                'slug' => $this->record->slug,
            ]);
            $form->setRelation('tenant', $this->record->tenant);
            $form->setAttribute('id', $this->record->getKey());

            return view('public.form', FormView::page($form, $this->record->tenant, true))->render();
        } catch (Throwable $e) {
            report($e);

            return '<p style="font-family:sans-serif;padding:1rem">Pratinjau belum bisa ditampilkan. Simpan dulu, lalu tekan Perbarui pratinjau.</p>';
        }
    }
}
