<?php

namespace App\Filament\Admin\Resources\Tenants;

use App\Billing\Subscriptions;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Models\Lead;
use App\Models\Subscription;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $modelLabel = 'agensi';

    protected static ?string $pluralModelLabel = 'Agensi';

    protected static ?string $navigationLabel = 'Agensi';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('subscription')->withCount('users'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Agensi')->searchable()->description(fn (Tenant $t) => $t->slug),
                TextColumn::make('plan_state')->label('Paket')->badge()
                    ->state(fn (Tenant $t) => $t->status === 'suspended' ? 'Ditangguhkan' : $t->currentSubscription()->statusLabel())
                    ->color(fn (Tenant $t) => $t->status === 'suspended' ? 'danger' : match ($t->currentSubscription()->effectiveStatus()) {
                        Subscription::ACTIVE => 'success', Subscription::TRIAL => 'warning', default => 'danger',
                    }),
                TextColumn::make('ends')->label('Berakhir')
                    ->state(fn (Tenant $t) => $t->currentSubscription()->endsAt())->dateTime('d M Y')->placeholder('-'),
                TextColumn::make('users_count')->label('Pengguna'),
                TextColumn::make('leads')->label('Lead')->state(fn (Tenant $t) => Lead::query()->withoutGlobalScopes()->where('tenant_id', $t->getKey())->count()),
                TextColumn::make('created_at')->label('Daftar')->dateTime('d M Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')->label('Status')->options([
                    'trial' => 'Uji coba', 'active' => 'Berlangganan', 'past_due' => 'Jatuh tempo', 'read_only' => 'Hanya-baca', 'suspended' => 'Ditangguhkan',
                ])->query(function ($query, array $data) {
                    $v = $data['value'] ?? null;

                    return match ($v) {
                        'suspended' => $query->where('status', 'suspended'),
                        'trial', 'active', 'past_due', 'read_only' => $query->whereHas('subscription', fn ($q) => $q->where('status', $v)),
                        default => $query,
                    };
                }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('setPlan')->label('Aktifkan paket')->icon(Heroicon::OutlinedCheckBadge)
                        ->schema([
                            Select::make('plan')->label('Paket')->options(collect(config('plans.plans'))->map->name->all())->required(),
                            Select::make('cycle')->label('Siklus')->options(['monthly' => 'Bulanan', 'yearly' => 'Tahunan'])->default('monthly')->required(),
                            TextInput::make('months')->label('Lama (bulan)')->numeric()->minValue(1)->maxValue(36)->default(1)->required(),
                        ])
                        ->action(function (Tenant $record, array $data) {
                            app(Subscriptions::class)->activate($record, $data['plan'], $data['cycle'], (int) $data['months']);
                            Notification::make()->title('Paket diaktifkan')->success()->send();
                        }),
                    Action::make('extendTrial')->label('Perpanjang uji coba')->icon(Heroicon::OutlinedClock)
                        ->visible(fn (Tenant $t) => $t->currentSubscription()->neverPaid())
                        ->schema([TextInput::make('days')->label('Tambah hari')->numeric()->minValue(1)->maxValue(90)->default(7)->required()])
                        ->action(function (Tenant $record, array $data) {
                            $sub = $record->currentSubscription();
                            $from = $sub->trial_ends_at?->isFuture() ? $sub->trial_ends_at : now();
                            $sub->update(['status' => Subscription::TRIAL, 'trial_ends_at' => $from->copy()->addDays((int) $data['days']), 'grace_ends_at' => null, 'reminder_sent_for' => null]);
                            Notification::make()->title('Uji coba diperpanjang')->success()->send();
                        }),
                    Action::make('addon')->label('Tambah add-on')->icon(Heroicon::OutlinedPlusCircle)
                        ->schema([
                            Select::make('kind')->label('Add-on')->options(collect(config('plans.addons'))->map(fn ($a) => $a['label'])->all())->required(),
                            TextInput::make('qty')->label('Jumlah (negatif untuk mengurangi)')->numeric()->default(1)->required(),
                        ])
                        ->action(function (Tenant $record, array $data) {
                            app(Subscriptions::class)->addAddon($record, $data['kind'], (int) $data['qty']);
                            Notification::make()->title('Add-on diperbarui')->success()->send();
                        }),
                    Action::make('suspend')->label('Tangguhkan')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                        ->visible(fn (Tenant $t) => $t->status !== 'suspended')
                        ->requiresConfirmation()->modalDescription('Pengguna agensi ini tidak bisa masuk, dan halaman publik serta formulirnya berhenti menerima lead, sampai diaktifkan kembali. Data tidak dihapus.')
                        ->action(fn (Tenant $record) => $record->update(['status' => 'suspended'])),
                    Action::make('unsuspend')->label('Aktifkan kembali')->icon(Heroicon::OutlinedPlay)->color('success')
                        ->visible(fn (Tenant $t) => $t->status === 'suspended')
                        ->action(fn (Tenant $record) => $record->update(['status' => 'active'])),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTenants::route('/')];
    }
}
