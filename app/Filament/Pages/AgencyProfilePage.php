<?php

namespace App\Filament\Pages;

use App\Agency\AgencyProfile;
use App\Enums\Role;
use App\LandingPages\ImageOptimizer;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Pengaturan > Profil agensi (admins only): the agency's name, logo, brand colour, business WhatsApp, contact e-mail,
 * address, time zone, "tentang kami" and website. Landing pages and message templates read them through
 * App\Agency\AgencyProfile. The slug (the agency's address in links) is shown but never changed here.
 */
class AgencyProfilePage extends Page
{
    protected string $view = 'filament.pages.agency-profile';

    protected static ?string $slug = 'profil-agensi';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'Profil agensi';

    protected static ?string $title = 'Profil agensi';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 1;

    public const LOGO_MAX_KB = 2048;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $current = app(CurrentTenant::class);

        return $current->get() !== null && $current->roleOf(auth()->user()) === Role::Admin;
    }

    public function mount(): void
    {
        $tenant = app(CurrentTenant::class)->get();
        $profile = AgencyProfile::for($tenant);

        $this->form->fill([
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'timezone' => array_key_exists((string) $tenant->timezone, AgencyProfile::TIMEZONES) ? $tenant->timezone : 'Asia/Jakarta',
            'logo' => $profile->logoPath(),
            'color' => $profile->color(),
            'whatsapp' => $profile->whatsappText(),
            'email' => $profile->email(),
            'address' => $profile->address(),
            'about' => $profile->about(),
            'website' => $profile->website(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $dir = fn () => 'landing/'.app(CurrentTenant::class)->id();

        return $schema->statePath('data')->components([
            Section::make('Identitas')->columns(2)->schema([
                TextInput::make('name')->label('Nama agensi')->required()->maxLength(120),
                TextInput::make('slug')->label('Alamat agensi (slug)')->disabled()->dehydrated(false)
                    ->helperText('Dipakai di semua tautan formulir dan landing page, jadi tidak bisa diubah di sini.'),
                FileUpload::make('logo')->label('Logo')->image()->disk('public')->visibility('public')->directory($dir)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(self::LOGO_MAX_KB)
                    ->saveUploadedFileUsing(fn ($file) => ImageOptimizer::store($file, $dir(), 600))
                    ->helperText('JPG, PNG, atau WebP, maksimal 2 MB. Dipakai di landing page yang belum punya logo sendiri.'),
                ColorPicker::make('color')->label('Warna merek')->regex(AgencyProfile::COLOR_PATTERN)
                    ->helperText('Warna bawaan landing page yang belum memilih warna sendiri.'),
                Select::make('timezone')->label('Zona waktu')->options(AgencyProfile::TIMEZONES)->required()->native(false),
            ]),
            Section::make('Kontak')->columns(2)->schema([
                TextInput::make('whatsapp')->label('WhatsApp bisnis')->tel()->maxLength(30)->placeholder('0812xxxxxxxx')
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                        if (filled($value) && PhoneNumber::whatsapp((string) $value) === null) {
                            $fail('Nomor WhatsApp bisnis tidak valid. Tulis nomor HP Indonesia, mis. 0812xxxxxxxx.');
                        }
                    })
                    ->helperText('Untuk variabel {wa_agensi} dan tombol WhatsApp landing page yang tidak punya nomor sendiri.'),
                TextInput::make('email')->label('Email kontak')->email()->maxLength(190),
                Textarea::make('address')->label('Alamat')->rows(2)->maxLength(500)->columnSpanFull(),
                Textarea::make('about')->label('Tentang kami')->rows(3)->maxLength(AgencyProfile::ABOUT_MAX)->columnSpanFull()
                    ->helperText('Teks pendek untuk kaki landing page dan pesan otomatis.'),
                TextInput::make('website')->label('Situs')->url()->maxLength(255)->placeholder('https://')->columnSpanFull(),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('save')->label('Simpan')->action('save')];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $tenant = app(CurrentTenant::class)->get();

        $tenant->forceFill([
            'name' => trim((string) $state['name']),
            'timezone' => array_key_exists((string) $state['timezone'], AgencyProfile::TIMEZONES) ? $state['timezone'] : $tenant->timezone,
            'profile' => AgencyProfile::clean($state) ?: null,
        ])->save();

        $this->mount();

        Notification::make()->title('Profil agensi disimpan')->success()->send();
    }
}
