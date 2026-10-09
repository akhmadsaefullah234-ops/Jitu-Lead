<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;

/**
 * Browsers and password managers fill the first text box above a password box
 * with a saved email and the password box with a saved password. These
 * attributes ask them to leave our settings forms alone.
 */
class NoAutofill
{
    private const ATTRIBUTES = ['data-lpignore' => 'true', 'data-1p-ignore' => 'true', 'data-bwignore' => 'true', 'data-form-type' => 'other'];

    public static function text(TextInput $input): TextInput
    {
        return $input->autocomplete('off')->extraInputAttributes(self::ATTRIBUTES);
    }

    public static function secret(TextInput $input): TextInput
    {
        return $input->password()->revealable()->autocomplete('new-password')->extraInputAttributes(self::ATTRIBUTES);
    }
}
