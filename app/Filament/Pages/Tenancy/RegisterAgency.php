<?php

namespace App\Filament\Pages\Tenancy;

use App\Actions\ProvisionTenant;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RegisterAgency extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Daftarkan agensi';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama agensi')
                ->required()
                ->maxLength(120)
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, callable $set, callable $get) => blank($get('slug')) ? $set('slug', Str::slug($state)) : null),
            TextInput::make('slug')
                ->label('Alamat ruang kerja')
                ->helperText('Huruf kecil, angka, dan tanda hubung. Contoh: griya-prima')
                ->required()
                ->minLength(3)
                ->maxLength(40)
                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                ->notIn(['app', 'admin', 'api', 'www', 'login', 'register'])
                ->unique('tenants', 'slug'),
        ]);
    }

    protected function handleRegistration(array $data): Model
    {
        return app(ProvisionTenant::class)($data['name'], $data['slug'], auth()->user());
    }
}
