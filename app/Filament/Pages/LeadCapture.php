<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The address and form snippet that send website visitors into the pipeline.
 */
class LeadCapture extends Page
{
    protected string $view = 'filament.pages.lead-capture';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Formulir web';

    protected static ?string $title = 'Formulir web';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 14;

    public static function canAccess(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin;
    }

    public function url(): ?string
    {
        return app(CurrentTenant::class)->get()?->captureUrl();
    }

    public function snippet(): string
    {
        $url = e($this->url());

        return <<<HTML
<form action="{$url}" method="post">
  <input name="name" placeholder="Nama" required>
  <input name="phone" placeholder="No. WhatsApp" required>
  <input name="note" placeholder="Pesan (opsional)">
  <input name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
  <button type="submit">Kirim</button>
</form>
HTML;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')->label(fn () => $this->url() ? 'Ganti alamat' : 'Aktifkan formulir')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->modalDescription(fn () => $this->url() ? 'Formulir yang sudah dipasang dengan alamat lama akan berhenti bekerja sampai Anda memasang alamat baru.' : 'Membuat alamat rahasia untuk formulir di website atau landing page Anda.')
                ->action(function () {
                    app(CurrentTenant::class)->get()->regenerateCaptureToken();
                    Notification::make()->title('Alamat formulir diperbarui')->success()->send();
                }),
        ];
    }
}
