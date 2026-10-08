<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Role: string implements HasLabel
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Agent = 'agent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Manager => 'Team leader',
            self::Agent => 'Agen',
        };
    }

    public function seesAllLeads(): bool
    {
        return $this !== self::Agent;
    }
}
