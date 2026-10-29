<?php

namespace App\Filament\Pages\Tenancy;

use App\Actions\ProvisionTenant;
use App\Retention\PlatformLeads;
use App\Support\OwnerAlerts;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
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
            self::industryField(),
        ]);
    }

    /** The kind of business, which picks the starting pipeline, lead fields and wording (config/industries.php). */
    public static function industryField(): Radio
    {
        $industries = config('industries.industries');

        return Radio::make('industry')
            ->label('Jenis bisnis')
            ->required()
            ->default(config('industries.default'))
            ->options(array_map(fn (array $i) => new HtmlString(
                '<span style="display:inline-flex;align-items:center;gap:.4rem;font-weight:600">'.svg($i['icon'], '', ['style' => 'width:1.25rem;height:1.25rem;color:var(--primary-600)'])->toHtml().e($i['label']).'</span>'
            ), $industries))
            ->descriptions(array_map(fn (array $i) => $i['description'], $industries))
            ->helperText('Menentukan tahap pipeline, kolom lead, dan istilah awal. Bisa diganti nanti di Pengaturan agensi.');
    }

    protected function handleRegistration(array $data): Model
    {
        $tenant = app(ProvisionTenant::class)($data['name'], $data['slug'], auth()->user(), $data['industry'] ?? null);

        OwnerAlerts::newAgency($tenant, auth()->user());
        app(PlatformLeads::class)->capture($tenant, auth()->user());

        return $tenant;
    }
}
