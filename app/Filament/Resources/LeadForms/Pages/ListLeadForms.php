<?php

namespace App\Filament\Resources\LeadForms\Pages;

use App\Enums\Role;
use App\Filament\Resources\LeadForms\LeadFormResource;
use App\Models\LeadForm;
use App\Support\CurrentTenant;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListLeadForms extends ListRecords
{
    protected static string $resource = LeadFormResource::class;

    /** Every agency has its main form, so the list is never empty and older links keep a form to show. */
    public function mount(): void
    {
        parent::mount();

        if (! LeadForm::query()->where('is_primary', true)->exists() && LeadFormResource::canCreate()) {
            LeadForm::primary();
        }
    }

    protected function getHeaderActions(): array
    {
        $tenant = fn () => app(CurrentTenant::class)->get();
        $admin = fn () => app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin;

        return [
            CreateAction::make()->label('Buat formulir'),
            Action::make('htmlCode')->label('Formulir HTML sendiri')->icon(Heroicon::OutlinedCodeBracket)->color('gray')
                ->visible(fn () => $admin() && filled($tenant()?->capture_token))
                ->modalHeading('Formulir HTML sendiri')->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                ->modalContent(fn () => view('filament.lead-form-html', ['endpoint' => $tenant()->captureUrl(), 'legacy' => route('form.show', $tenant()->capture_token)])),
            Action::make('generate')->label(fn () => filled($tenant()?->capture_token) ? 'Ganti alamat lama' : 'Aktifkan alamat HTML')
                ->icon(Heroicon::OutlinedArrowPath)->color('gray')->visible($admin)
                ->requiresConfirmation()
                ->modalDescription(fn () => filled($tenant()?->capture_token)
                    ? 'Tautan /f/ lama dan formulir HTML yang memakai alamat lama berhenti bekerja. Formulir yang dibuat di menu ini tidak terpengaruh. Pakai ini bila alamat disalahgunakan.'
                    : 'Membuat alamat untuk formulir HTML buatan sendiri di website Anda.')
                ->action(function () use ($tenant) {
                    $tenant()->regenerateCaptureToken();
                    Notification::make()->title('Alamat diperbarui')->success()->send();
                }),
        ];
    }
}
