<?php

namespace App\Filament\Resources\Leads\Schemas;

use App\Automation\StageAutomationSettings;
use App\Enums\Interest;
use App\Enums\Need;
use App\Enums\PaymentMethod;
use App\LeadForms\FormFields;
use App\Models\Lead;
use App\Support\CurrentTenant;
use App\Support\Industry;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
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
                        TextInput::make('shipping_cost')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rp'),
                        Select::make('payment_type')
                            ->options(['transfer' => 'Transfer', 'cod' => 'Bayar di tempat (COD)']),
                        TextInput::make('tracking_number')
                            ->maxLength(60)
                            ->regex('/^[A-Za-z0-9\-\s]{4,60}$/')
                            ->helperText(fn () => StageAutomationSettings::appliesTo(app(CurrentTenant::class)->get()) && StageAutomationSettings::of(app(CurrentTenant::class)->get())->enabled('tracking')
                                ? 'Saat disimpan, kurir dan nomor resi dikirim ke pembeli lewat WhatsApp dan lead pindah ke Dikemas & dikirim.' : null)
                            ->hiddenOn('create'),
                        Textarea::make('shipping_address')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Placeholder::make('shipping_address_parts_view')
                            ->label('Rincian alamat dari formulir')
                            ->columnSpanFull()
                            ->content(fn (?Lead $record) => collect(FormFields::ADDRESS_PARTS)
                                ->map(fn (string $label, string $part) => filled($record?->shipping_address_parts[$part] ?? null) ? $label.': '.$record->shipping_address_parts[$part] : null)
                                ->filter()->implode(' · ')),
                    ])),
                Section::make('Jawaban formulir')
                    ->columnSpanFull()->collapsible()
                    ->visible(fn (?Lead $record) => filled($record?->custom_fields['formulir'] ?? null) || filled($record?->custom_fields['foto_formulir'] ?? null))
                    ->schema([View::make('filament.lead-form-answers')]),
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
    private static function industryField(Field|Placeholder $field): Field|Placeholder
    {
        if ($field instanceof Placeholder) {
            // The address details a form sent, shown with the address.
            return $field->visible(fn (?Lead $record) => Industry::current()->usesLeadField('shipping_address') && filled($record?->shipping_address_parts));
        }

        return $field
            ->label(fn () => Industry::current()->leadFields()[$field->getName()] ?? $field->getName())
            ->visible(fn () => Industry::current()->usesLeadField($field->getName()));
    }

    private static function canAssign(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user())?->seesAllLeads() ?? false;
    }
}
