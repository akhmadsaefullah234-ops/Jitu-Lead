<?php

namespace App\Filament\Resources\LandingPages\Pages;

use App\Filament\Resources\LandingPages\LandingPageResource;
use App\LandingPages\BlockFormat;
use App\LandingPages\PageData;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Throwable;

class EditLandingPage extends EditRecord
{
    protected static string $resource = LandingPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label('Pratinjau di tab baru')->icon(Heroicon::OutlinedEye)->color('gray')
                ->url(fn () => LandingPageResource::previewUrl($this->record), shouldOpenInNewTab: true),
            Action::make('duplicate')->label('Duplikat')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')
                ->visible(fn () => LandingPageResource::canCreate())
                ->action(function () {
                    if ($copy = LandingPageResource::duplicate($this->record)) {
                        Notification::make()->success()->title('Halaman diduplikat sebagai draf')->send();

                        return redirect(LandingPageResource::getUrl('edit', ['record' => $copy]));
                    }
                }),
            Action::make('visit')->label('Buka halaman')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                ->visible(fn () => $this->record->isPublished())
                ->url(fn () => $this->record->publicUrl(), shouldOpenInNewTab: true),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['blocks'] = BlockFormat::toForm($data['blocks'] ?? []);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['blocks'] = BlockFormat::fromForm($data['blocks'] ?? []);

        return static::withPublishing($data, $this->record->published_at !== null);
    }

    /**
     * The page as it is in the editor right now, drawn like the public page,
     * for the side preview. Nothing is saved; a picture picked but not yet
     * saved only shows after Simpan. No scripts run inside it.
     */
    public function previewHtml(): string
    {
        try {
            $state = $this->form->getRawState();
            $page = $this->record->replicate();
            $page->forceFill(array_merge(
                Arr::only($state, ['title', 'color', 'font', 'description', 'meta_title', 'whatsapp_number', 'logo', 'og_image']),
                ['blocks' => BlockFormat::fromForm($state['blocks'] ?? []), 'slug' => $this->record->slug],
            ));
            $page->setRelation('tenant', $this->record->tenant);
            $page->setAttribute('id', $this->record->getKey());

            return view('public.landing', PageData::for($page, $this->record->tenant, true))->render();
        } catch (Throwable $e) {
            report($e);

            return '<p style="font-family:sans-serif;padding:1rem">Pratinjau belum bisa ditampilkan. Simpan dulu, lalu tekan Perbarui pratinjau.</p>';
        }
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
