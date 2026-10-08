<?php

namespace App\Filament\Resources\Leads\Schemas;

use App\Enums\Interest;
use App\Enums\Need;
use App\Enums\PaymentMethod;
use App\Models\Lead;
use App\Support\CurrentTenant;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
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
                Section::make('Kebutuhan properti')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        ToggleButtons::make('need')
                            ->label('Kebutuhan')
                            ->options(Need::class)
                            ->default(Need::Buy)
                            ->inline()
                            ->required(),
                        Select::make('property_type')
                            ->label('Tipe properti')
                            ->options(self::PROPERTY_TYPES),
                        TextInput::make('location')
                            ->label('Lokasi incaran')
                            ->maxLength(120),
                        Select::make('payment_method')
                            ->label('Cara bayar')
                            ->options(PaymentMethod::class),
                        TextInput::make('budget_min')
                            ->label('Budget minimum')
                            ->prefix('Rp')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('budget_max')
                            ->label('Budget maksimum')
                            ->prefix('Rp')
                            ->numeric()
                            ->minValue(0)
                            ->gte('budget_min'),
                        Select::make('property_id')
                            ->label('Properti diminati')
                            ->relationship('property', 'name')
                            ->searchable()
                            ->preload()
                            ->columnSpanFull(),
                    ]),
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

    private static function canAssign(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user())?->seesAllLeads() ?? false;
    }
}
