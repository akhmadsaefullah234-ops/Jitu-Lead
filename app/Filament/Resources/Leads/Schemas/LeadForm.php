<?php

namespace App\Filament\Resources\Leads\Schemas;

use App\Enums\Interest;
use App\Enums\Need;
use App\Enums\PaymentMethod;
use App\Models\Lead;
use App\Support\CurrentTenant;
use App\Support\Industry;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class LeadForm
{
    public const PROPERTY_TYPES = [
        'Rumah' => 'Rumah',
        'Apartemen' => 'Apartemen',
        'Ruko' => 'Ruko',
        'Tanah' => 'Tanah',
        'Kavling' => 'Kavling',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Kontak')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(120),
                        TextInput::make('phone')
                            ->label('Telepon / WhatsApp')
                            ->tel()
                            ->placeholder('0812 3456 7890')
                            ->requiredWithout('email')
                            ->validationMessages(['required_without' => 'Isi nomor telepon atau email.'])
                            ->maxLength(30),
                        TextInput::make('email')
                            ->email()
                            ->requiredWithout('phone')
                            ->validationMessages(['required_without' => 'Isi nomor telepon atau email.'])
                            ->maxLength(120),
                        Select::make('lead_source_id')
                            ->label('Sumber')
                            ->relationship('source', 'name')
                            ->preload(),
                    ]),
                Section::make(fn () => Industry::t('lead_section'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible(fn () => self::showsIndustryFields())
                    ->schema(array_map(self::industryField(...), [
                        ToggleButtons::make('need')
                            ->options(Need::class)
                            ->default(Need::Buy)
                            ->inline()
                            ->required(),
                        Select::make('property_type')
                            ->options(self::PROPERTY_TYPES),
                        TextInput::make('location')
                            ->maxLength(120),
                        Select::make('payment_method')
                            ->options(PaymentMethod::class),
                        TextInput::make('budget_min')
                            ->prefix('Rp')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('budget_max')
                            ->prefix('Rp')
                            ->numeric()
                            ->minValue(0)
                            ->gte('budget_min'),
                        Select::make('property_id')
                            ->relationship('property', 'name')
                            ->searchable()
                            ->preload()
                            ->columnSpanFull(),
                        TextInput::make('variant')
                            ->placeholder('Contoh: Hitam, L')
                            ->maxLength(120),
                        TextInput::make('quantity')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100000),
                        TextInput::make('courier')
                            ->placeholder('JNE, J&T, SiCepat, GoSend')
                            ->maxLength(60),
                        Textarea::make('shipping_address')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ])),
                Section::make('Tindak lanjut')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        ToggleButtons::make('interest')
                            ->label('Tingkat minat')
                            ->options(Interest::class)
                            ->default(Interest::Warm)
                            ->inline()
                            ->required(),
                        Select::make('owner_id')
                            ->label('Agen')
                            ->helperText('Kosongkan untuk dibagi otomatis bergiliran.')
                            ->options(fn () => app(CurrentTenant::class)->get()?->activeUsers()->orderBy('name')->pluck('name', 'users.id') ?? [])
                            ->visible(fn (?Lead $record) => self::canAssign())
                            ->searchable(),
                        TextInput::make('next_action')
                            ->label('Aksi berikutnya')
                            ->maxLength(200)
                            ->required(fn (?Lead $record) => $record !== null && $record->stage?->isOpen())
                            ->hiddenOn('create'),
                        DateTimePicker::make('next_action_due_at')
                            ->label('Tenggat aksi')
                            ->seconds(false)
                            ->required(fn (Get $get) => filled($get('next_action')))
                            ->hiddenOn('create'),
                    ]),
            ]);
    }

    /** An agency can hide its industry's fields ("Pengaturan agensi" on the lead list). */
    public static function showsIndustryFields(): bool
    {
        return app(CurrentTenant::class)->get()?->showsIndustryFields() ?? true;
    }

    /** Shows the field only for the industries that use it, under that industry's label (config/industries.php). */
    private static function industryField(Field $field): Field
    {
        return $field
            ->label(fn () => Industry::current()->leadFields()[$field->getName()] ?? $field->getName())
            ->visible(fn () => Industry::current()->usesLeadField($field->getName()));
    }

    private static function canAssign(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user())?->seesAllLeads() ?? false;
    }
}
