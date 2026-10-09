<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The lead form for the agency's own website: shown exactly as visitors see
 * it, with the link and the iframe code that put it online.
 */
class LeadCapture extends Page
{
    protected string $view = 'filament.pages.lead-capture';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Formulir web';

    protected static ?string $title = 'Formulir web';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 14;

    public static function canAccess(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin;
    }

    private function tenant()
    {
        return app(CurrentTenant::class)->get();
    }

    /** Address of the hosted form: a page to link to, and what the iframe shows. */
    public function formUrl(): ?string
    {
        $token = $this->tenant()?->capture_token;

        return $token ? route('form.show', $token) : null;
    }

    public function endpointUrl(): ?string
    {
        return $this->tenant()?->captureUrl();
    }

    public function previewUrl(): ?string
    {
        // The tenant's updated_at changes with every saved setting, so the preview reloads after an edit.
        return $this->formUrl() ? $this->formUrl().'?v='.$this->tenant()->updated_at?->timestamp : null;
    }

    public function iframeCode(): string
    {
        return '<iframe src="'.e($this->formUrl()).'" title="Formulir pendaftaran" style="width:100%;max-width:480px;height:560px;border:0" loading="lazy"></iframe>';
    }

    public function htmlCode(): string
    {
        $url = e($this->endpointUrl());

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
            Action::make('design')->label('Atur tampilan')->icon(Heroicon::OutlinedPaintBrush)
                ->visible(fn () => $this->formUrl() !== null)
                ->modalHeading('Tampilan dan isi formulir')
                ->fillForm(fn () => $this->tenant()->formSettings())
                ->schema([
                    TextInput::make('title')->label('Judul')->required()->maxLength(100),
                    Textarea::make('intro')->label('Kalimat pengantar')->rows(2)->maxLength(200),
                    TextInput::make('button')->label('Teks tombol')->required()->maxLength(40),
                    TextInput::make('success')->label('Pesan setelah terkirim')->required()->maxLength(200),
                    ColorPicker::make('color')->label('Warna utama')->required()->regex('/^#[0-9a-fA-F]{6}$/'),
                    Toggle::make('ask_email')->label('Tanyakan email'),
                    Toggle::make('ask_note')->label('Tampilkan kolom pesan'),
                    Toggle::make('show_privacy')->label('Tampilkan catatan privasi di bawah tombol')->live(),
                    TextInput::make('privacy')->label('Isi catatan privasi')->maxLength(250)->visible(fn (callable $get) => (bool) $get('show_privacy')),
                ])
                ->action(function (array $data) {
                    $tenant = $this->tenant();
                    $tenant->forceFill(['capture_settings' => $data])->save();
                    Notification::make()->title('Tampilan formulir disimpan')->success()->send();
                }),
            Action::make('generate')->label(fn () => $this->formUrl() ? 'Ganti alamat' : 'Aktifkan formulir')
                ->icon(Heroicon::OutlinedArrowPath)->color(fn () => $this->formUrl() ? 'gray' : 'primary')
                ->requiresConfirmation()
                ->modalDescription(fn () => $this->formUrl() ? 'Formulir dan kode yang sudah terpasang dengan alamat lama akan berhenti bekerja sampai Anda memasang kode baru. Pakai ini bila alamat disalahgunakan.' : 'Membuat alamat formulir untuk website atau landing page Anda.')
                ->action(function () {
                    $this->tenant()->regenerateCaptureToken();
                    Notification::make()->title('Alamat formulir diperbarui')->success()->send();
                }),
        ];
    }
}
