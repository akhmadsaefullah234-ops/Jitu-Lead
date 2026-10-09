<?php

namespace App\Filament\Pages\Auth;

use App\Models\RegistrationInvite;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
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

    public function form(Schema $schema): Schema
    {
        return $schema->components(array_filter([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            static::needsInvite() ? $this->getInviteFormComponent() : null,
        ]));
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
        unset($data['invite_code']);

        if (static::needsInvite() && ! RegistrationInvite::consume($code)) {
            throw ValidationException::withMessages(['data.invite_code' => 'Kode undangan tidak berlaku atau sudah habis dipakai.']);
        }

        return parent::handleRegistration($data);
    }
}
