<?php

namespace App\Filament\Admin\Pages;

use App\Models\PlatformSetting;
use App\Support\PhoneNumber;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** The business contact a payment gateway asks for. Shown in full on /kontak and the legal pages; the front page follows the switches here. */
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
                TextInput::make('phone')->label('Nomor HP')->tel()->maxLength(30)
                    ->helperText('Contoh: 082113345882 atau +62 821-1334-5882. Tulisan tetap seperti yang Anda ketik; tautan WhatsApp dan telepon otomatis memakai 62.')
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                        if (filled($value) && PhoneNumber::whatsapp($value) === null) {
                            $fail('Isi dengan nomor HP Indonesia, misalnya 082113345882 atau +62 821-1334-5882.');
                        }
                    }),
                Toggle::make('show_phone')->label('Tampilkan di halaman depan')->default(true),
            ]),
            Section::make('Alamat usaha')->description('Alamat lengkap selalu tampil di /kontak dan di halaman hukum. Pilihan di bawah hanya untuk halaman depan.')->schema([
                Textarea::make('address')->label('Alamat lengkap')->rows(3)->maxLength(300),
                TextInput::make('city')->label('Kota / kabupaten')->maxLength(80)->placeholder('Kab. Bekasi')
                    ->required(fn (Get $get) => $get('address_display') === PlatformSetting::ADDRESS_CITY),
                Select::make('address_display')->label('Di halaman depan tampilkan')->native(false)->live()->required()
                    ->options([
                        PlatformSetting::ADDRESS_FULL => 'Alamat lengkap',
                        PlatformSetting::ADDRESS_CITY => 'Kota saja',
                        PlatformSetting::ADDRESS_HIDDEN => 'Sembunyikan',
                    ]),
            ]),
            Section::make('Jam layanan')->schema([
                TextInput::make('hours')->label('Jam layanan')->maxLength(120)->placeholder('Senin sampai Jumat, 09.00 sampai 17.00 WIB')
                    ->helperText('Tampil di /kontak. Kosongkan bila tidak ingin menampilkannya.'),
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
        $text = fn (string $key) => filled($s[$key] ?? null) ? trim($s[$key]) : null;

        PlatformSetting::write('contact', [
            'email' => $text('email'),
            'phone' => $text('phone'),
            'address' => $text('address'),
            'city' => $text('city'),
            'hours' => $text('hours'),
            'address_display' => $s['address_display'] ?? PlatformSetting::ADDRESS_FULL,
            'show_email' => (bool) ($s['show_email'] ?? false),
            'show_phone' => (bool) ($s['show_phone'] ?? false),
        ]);

        Notification::make()->title('Tersimpan')->success()->send();
    }
}
