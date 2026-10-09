<?php

namespace App\Filament\Resources\LandingPages\Pages;

use App\Filament\Resources\LandingPages\LandingPageResource;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditLandingPage extends EditRecord
{
    protected static string $resource = LandingPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label('Pratinjau')->icon(Heroicon::OutlinedEye)->color('gray')
                ->modalHeading('Pratinjau halaman')->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                ->modalContent(fn () => view('filament.landing-preview', ['url' => LandingPageResource::previewUrl($this->record->refresh())])),
            Action::make('visit')->label('Buka halaman')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                ->visible(fn () => $this->record->isPublished())
                ->url(fn () => $this->record->publicUrl(), shouldOpenInNewTab: true),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return static::withPublishing($data, $this->record->published_at !== null);
    }

    protected function afterSave(): void
    {
        static::ensureCaptureToken();
    }

    /**
     * Stamps the first publication time.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withPublishing(array $data, bool $alreadyPublished = false): array
    {
        if (($data['status'] ?? 'draft') === 'published' && ! $alreadyPublished) {
            $data['published_at'] = now();
        }

        return $data;
    }

    /**
     * Pages post to the agency's capture address, so it must exist.
     */
    public static function ensureCaptureToken(): void
    {
        $tenant = app(CurrentTenant::class)->get();

        if ($tenant instanceof Tenant && blank($tenant->capture_token)) {
            $tenant->regenerateCaptureToken();
        }
    }
}
