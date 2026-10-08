<?php

namespace App\Filament\Resources\WaChannels;

use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Resources\WaChannels\Pages\CreateWaChannel;
use App\Filament\Resources\WaChannels\Pages\EditWaChannel;
use App\Filament\Resources\WaChannels\Pages\ListWaChannels;
use App\Models\WaChannel;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\Providers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class WaChannelResource extends Resource
{
    protected static ?string $model = WaChannel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static ?string $modelLabel = 'nomor WhatsApp';

    protected static ?string $pluralModelLabel = 'koneksi WhatsApp';

    protected static ?string $navigationLabel = 'Koneksi WhatsApp';

    protected static ?int $navigationSort = 20;

    /** Credential fields per type. Secrets are never sent back to the browser. */
    public const SECRETS = ['access_token', 'app_secret', 'verify_token', 'api_key', 'signing_secret'];

    public static function form(Schema $schema): Schema
    {
        $secret = fn (string $key, string $label) => TextInput::make("cred.$key")->label($label)->password()->revealable()
            ->required(fn (?WaChannel $record) => $record === null)
            ->placeholder(fn (?WaChannel $record) => $record ? 'Tersimpan. Kosongkan jika tidak diubah.' : null)
            ->autocomplete('off');

        return $schema->components([
            Section::make('Nomor')->columns(2)->schema([
                Select::make('type')->label('Jenis')->options(WaChannelType::class)->required()->live()
                    ->disabled(fn (?WaChannel $record) => $record !== null)->dehydrated(),
                TextInput::make('name')->label('Nama nomor')->required()->maxLength(80)->placeholder('Contoh: CS Iklan, Gateway 1'),
                TextInput::make('phone')->label('Nomor telepon')->maxLength(30),
                TextInput::make('position')->label('Urutan pakai')->numeric()->default(0)->minValue(0)
                    ->helperText('Untuk gateway: nomor dengan urutan terkecil dipakai dulu, sisanya cadangan.'),
            ]),
            Section::make('Akses WhatsApp Business API')->columns(2)
                ->visible(fn (Get $get) => static::typeOf($get('type')) === 'official')
                ->schema([
                    TextInput::make('cred.phone_number_id')->label('Phone number ID')->required(),
                    $secret('access_token', 'Access token'),
                    $secret('app_secret', 'App secret (verifikasi tanda tangan)'),
                    $secret('verify_token', 'Verify token webhook'),
                ]),
            Section::make('Akses gateway')->columns(2)
                ->visible(fn (Get $get) => static::typeOf($get('type')) === 'gateway')
                ->schema([
                    TextInput::make('cred.base_url')->label('Alamat gateway')->required()->url()->placeholder('https://gateway.contoh.com')
                        ->rules([fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail) {
                            if ($problem = GatewayUrlGuard::problem((string) $value)) {
                                $fail($problem);
                            }
                        }]),
                    $secret('api_key', 'API key'),
                    $secret('signing_secret', 'Signing secret (verifikasi webhook)'),
                    Textarea::make('intro_template')->label('Pesan perkenalan')->rows(3)->columnSpanFull()
                        ->placeholder(WaChannel::DEFAULT_INTRO)
                        ->helperText('Dikirim lebih dulu saat nomor ini pertama kali menulis ke klien. Variabel: {nama} {agen} {agensi} {properti}.'),
                ]),
            Section::make('Webhook')->visible(fn (?WaChannel $record) => $record !== null)->schema([
                Placeholder::make('webhook')->label('Daftarkan alamat ini di Meta atau di gateway')
                    ->content(fn (?WaChannel $record) => $record?->webhookUrl()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('position')->columns([
            TextColumn::make('name')->label('Nama')->searchable(),
            TextColumn::make('type')->label('Jenis')->badge(),
            TextColumn::make('phone')->label('Nomor'),
            TextColumn::make('status')->label('Status')->badge()
                ->color(fn (WaChannelStatus $state) => $state->getColor()),
            TextColumn::make('last_error')->label('Catatan')->limit(50)->placeholder('-'),
            TextColumn::make('position')->label('Urutan')->sortable(),
        ])->recordActions([
            Action::make('test')->label('Tes koneksi')->icon(Heroicon::OutlinedSignal)->action(function (WaChannel $record) {
                $problem = app(Providers::class)->for($record)->checkConnection();

                if ($problem === null) {
                    $record->markConnected();
                    Notification::make()->title('Terhubung')->success()->send();
                } else {
                    $record->markFailed($problem);
                    Notification::make()->title('Belum terhubung')->body($problem)->danger()->send();
                }
            }),
            EditAction::make(),
            DeleteAction::make(),
        ]);
    }

    /**
     * Folds the submitted credential fields into the stored ones; a blank
     * secret keeps what is already saved.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withCredentials(array $data, ?WaChannel $existing = null): array
    {
        $submitted = (array) ($data['cred'] ?? []);
        unset($data['cred']);

        $credentials = $existing?->credentials ?? [];
        foreach ($submitted as $key => $value) {
            if (filled($value)) {
                $credentials[$key] = trim((string) $value);
            }
        }

        $type = $data['type'] ?? $existing?->type;
        $type = $type instanceof WaChannelType ? $type->value : $type;

        if ($type === 'gateway' && ($problem = GatewayUrlGuard::problem((string) ($credentials['base_url'] ?? '')))) {
            throw ValidationException::withMessages(['data.cred.base_url' => $problem]);
        }

        $data['credentials'] = $credentials;

        return $data;
    }

    private static function typeOf(mixed $state): ?string
    {
        return $state instanceof WaChannelType ? $state->value : $state;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWaChannels::route('/'),
            'create' => CreateWaChannel::route('/create'),
            'edit' => EditWaChannel::route('/{record}/edit'),
        ];
    }
}
