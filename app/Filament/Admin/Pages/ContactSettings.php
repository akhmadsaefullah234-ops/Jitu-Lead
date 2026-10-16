<?php

namespace App\Filament\Admin\Pages;

use App\Models\PlatformSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** The business contact a payment gateway asks for, and which parts of it the front page shows. */
class ContactSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static ?string $navigationLabel = 'Kontak usaha';

    protected static ?string $title = 'Kontak usaha';

    protected static ?string $slug = 'contact';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.admin.contact-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(PlatformSetting::contact());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Email')->schema([
                TextInput::make('email')->label('Email')->email()->maxLength(120),
                Toggle::make('show_email')->label('Tampilkan di halaman depan')->default(true),
            ]),
            Section::make('Telepon / WhatsApp')->schema([
                TextInput::make('phone')->label('Nomor kontak')->tel()->maxLength(30)->helperText('Contoh: 082113345882'),
                Toggle::make('show_phone')->label('Tampilkan di halaman depan')->default(true),
            ]),
            Section::make('Alamat usaha')->schema([
                Textarea::make('address')->label('Alamat')->rows(3)->maxLength(300),
                Toggle::make('show_address')->label('Tampilkan di halaman depan')->default(true),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('save')->label('Simpan')->action('save')];
    }

    public function save(): void
    {
        $s = $this->form->getState();

        PlatformSetting::write('contact', [
            'email' => filled($s['email'] ?? null) ? trim($s['email']) : null,
            'phone' => filled($s['phone'] ?? null) ? trim($s['phone']) : null,
            'address' => filled($s['address'] ?? null) ? trim($s['address']) : null,
            'show_email' => (bool) ($s['show_email'] ?? false),
            'show_phone' => (bool) ($s['show_phone'] ?? false),
            'show_address' => (bool) ($s['show_address'] ?? false),
        ]);

        Notification::make()->title('Tersimpan')->success()->send();
    }
}
