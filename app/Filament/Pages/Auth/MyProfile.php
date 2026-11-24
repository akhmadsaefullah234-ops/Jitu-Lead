<?php

namespace App\Filament\Pages\Auth;

use App\LandingPages\ImageOptimizer;
use App\Support\PhoneNumber;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Profil saya, for every role: name, e-mail (a new address is confirmed by a link first, Filament's email change
 * verification), WhatsApp number, photo, and the password (the old one is asked). "Keluar dari perangkat lain" ends
 * every other session. The role is not on this page: only an agency admin changes roles, in Tim.
 */
class MyProfile extends EditProfile
{
    public const AVATAR_MAX_KB = 2048;

    public static function getLabel(): string
    {
        return 'Profil saya';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Profil saya';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Data diri')->schema([
                FileUpload::make('avatar_path')->label('Foto')->avatar()->image()->disk('public')->visibility('public')
                    ->directory(fn () => 'avatars/'.$this->getUser()->getKey())
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(self::AVATAR_MAX_KB)
                    ->saveUploadedFileUsing(fn ($file) => ImageOptimizer::store($file, 'avatars/'.$this->getUser()->getKey(), 400))
                    ->helperText('JPG, PNG, atau WebP, maksimal 2 MB.'),
                $this->getNameFormComponent()->label('Nama'),
                $this->getEmailFormComponent()->label('Email')
                    ->helperText('Bila diganti, kami kirim tautan konfirmasi ke email baru. Email lama tetap dipakai sampai tautan itu dibuka.'),
                TextInput::make('whatsapp')->label('Nomor WhatsApp')->tel()->maxLength(30)->placeholder('0812xxxxxxxx')
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                        if (filled($value) && PhoneNumber::whatsapp((string) $value) === null) {
                            $fail('Nomor WhatsApp tidak valid. Tulis nomor HP Indonesia, mis. 0812xxxxxxxx.');
                        }
                    }),
            ]),
            Section::make('Kata sandi')->description('Kosongkan bila tidak ingin mengganti.')->schema([
                $this->getPasswordFormComponent()->label('Kata sandi baru'),
                $this->getPasswordConfirmationFormComponent()->label('Ulangi kata sandi baru'),
                $this->getCurrentPasswordFormComponent()->label('Kata sandi lama')
                    ->belowContent('Wajib diisi untuk mengganti kata sandi atau email.'),
            ]),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->getUser();

        return [
            'name' => $user->name,
            'email' => $user->email,
            'whatsapp' => filled($user->whatsapp) ? '0'.substr((string) $user->whatsapp, 2) : null,
            'avatar_path' => $user->avatar_path,
        ];
    }

    /** Only these fields are ever written: never a role, an admin flag or another user's data. */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        $avatar = $data['avatar_path'] ?? null;
        $avatar = is_array($avatar) ? (reset($avatar) ?: null) : $avatar;
        $whatsapp = PhoneNumber::whatsapp($data['whatsapp'] ?? null);

        $record->forceFill([
            'avatar_path' => is_string($avatar) && str_starts_with($avatar, 'avatars/'.$record->getKey().'/') ? $avatar : null,
        ]);

        if ($whatsapp !== $record->whatsapp) {
            // A changed number has to be confirmed again for the daily report (hasVerifiedWhatsapp compares it).
            $record->setWhatsapp($whatsapp);
        }

        return parent::handleRecordUpdate($record, array_intersect_key($data, array_flip(['name', 'email', 'password'])));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('logoutOthers')->label('Keluar dari perangkat lain')->color('gray')
                ->modalHeading('Keluar dari perangkat lain')
                ->modalDescription('Semua sesi lain akun ini (HP, laptop lain) langsung keluar. Sesi ini tetap masuk.')
                ->schema([
                    TextInput::make('password')->label('Kata sandi')->password()->required()->autocomplete('current-password'),
                ])
                ->action(fn (array $data) => $this->logoutOtherDevices($data['password'])),
        ];
    }

    public function logoutOtherDevices(#[SensitiveParameter] string $password): void
    {
        $user = $this->getUser();

        if (! Hash::check($password, (string) $user->getAuthPassword())) {
            throw ValidationException::withMessages(['mountedActions.0.data.password' => 'Kata sandi salah.']);
        }

        Auth::guard(Filament::getAuthGuard())->logoutOtherDevices($password);

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())
                ->where('id', '!=', request()->session()->getId())->delete();
        }

        if (request()->hasSession()) {
            request()->session()->put(['password_hash_'.Filament::getAuthGuard() => $user->fresh()->getAuthPassword()]);
        }

        Notification::make()->title('Perangkat lain sudah dikeluarkan')->success()->send();
    }
}
