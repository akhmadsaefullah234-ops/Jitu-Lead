<?php

namespace App\Filament\Pages\Auth;

use App\Models\RegistrationInvite;
use App\Models\User;
use App\Support\PhoneNumber;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Sign-up. In "invite" mode it also asks for an invitation code, which is
 * spent in the same transaction that creates the account.
 */
class Register extends BaseRegister
{
    public static function needsInvite(): bool
    {
        return config('jitu.registration') !== 'open';
    }

    public function getHeading(): string|Htmlable
    {
        return static::needsInvite() ? 'Daftar dengan undangan' : 'Daftar dan coba gratis '.config('plans.trial_days').' hari';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(array_filter([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            static::whatsappField(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            static::needsInvite() ? $this->getInviteFormComponent() : null,
            static::consentField(),
        ]));
    }

    /** Required Indonesian WhatsApp number, saved as 62812... (also used by the dashboard prompt for older accounts). */
    public static function whatsappField(): TextInput
    {
        return TextInput::make('whatsapp')
            ->label('Nomor WhatsApp')
            ->tel()
            ->required()
            ->maxLength(30)
            ->placeholder('0812 3456 7890')
            ->helperText('Untuk informasi akun dan bantuan memakai JITU LEAD.')
            ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail) {
                if (PhoneNumber::whatsapp((string) $value) === null) {
                    $fail('Isi nomor WhatsApp Indonesia yang valid, misalnya 0812 3456 7890 atau +62 812 3456 7890.');
                }
            });
    }

    public static function consentField(): Checkbox
    {
        return Checkbox::make('contact_consent')
            ->label('Saya bersedia dihubungi via WhatsApp/email tentang akun dan penawaran JITU LEAD');
    }

    protected function getInviteFormComponent(): Component
    {
        return TextInput::make('invite_code')
            ->label('Kode undangan')
            ->required()
            ->maxLength(40)
            ->autocomplete('off')
            ->helperText('Pendaftaran saat ini hanya dengan undangan. Minta kode ke penyedia aplikasi.')
            ->rules([fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail) {
                if (! RegistrationInvite::usable((string) $value)) {
                    $fail('Kode undangan tidak berlaku atau sudah habis dipakai.');
                }
            }]);
    }

    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $code = $data['invite_code'] ?? null;
        $whatsapp = $data['whatsapp'] ?? null;
        $consent = (bool) ($data['contact_consent'] ?? false);
        unset($data['invite_code'], $data['whatsapp'], $data['contact_consent']);

        if (static::needsInvite() && ! RegistrationInvite::consume($code)) {
            throw ValidationException::withMessages(['data.invite_code' => 'Kode undangan tidak berlaku atau sudah habis dipakai.']);
        }

        /** @var User $user */
        $user = parent::handleRegistration($data);
        $user->setWhatsapp($whatsapp);
        $user->forceFill(['contact_consent_at' => $consent ? now() : null])->save();

        return $user;
    }
}
