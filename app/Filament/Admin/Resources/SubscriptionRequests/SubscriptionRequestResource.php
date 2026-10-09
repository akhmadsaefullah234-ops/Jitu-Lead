<?php

namespace App\Filament\Admin\Resources\SubscriptionRequests;

use App\Billing\Subscriptions;
use App\Filament\Admin\Resources\SubscriptionRequests\Pages\ListSubscriptionRequests;
use App\Models\SubscriptionRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubscriptionRequestResource extends Resource
{
    protected static ?string $model = SubscriptionRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $modelLabel = 'permintaan paket';

    protected static ?string $pluralModelLabel = 'Permintaan paket';

    protected static ?string $navigationLabel = 'Permintaan paket';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = SubscriptionRequest::query()->where('status', SubscriptionRequest::PENDING)->count();

        return $n ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tenant'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('tenant.name')->label('Agensi')->searchable(),
                TextColumn::make('plan')->label('Paket')->formatStateUsing(fn ($state) => config("plans.plans.$state.name", $state)),
                TextColumn::make('billing_cycle')->label('Siklus')->formatStateUsing(fn ($state) => $state === 'yearly' ? 'Tahunan' : 'Bulanan'),
                TextColumn::make('amount')->label('Nominal')->money('IDR', locale: 'id'),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => ['pending' => 'Menunggu', 'approved' => 'Disetujui', 'cancelled' => 'Dibatalkan'][$state] ?? $state)
                    ->color(fn ($state) => ['pending' => 'warning', 'approved' => 'success'][$state] ?? 'gray'),
                TextColumn::make('created_at')->label('Diminta')->since(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['pending' => 'Menunggu', 'approved' => 'Disetujui', 'cancelled' => 'Dibatalkan'])->default('pending'),
            ])
            ->recordActions([
                Action::make('approve')->label('Setujui (transfer diterima)')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (SubscriptionRequest $r) => $r->status === SubscriptionRequest::PENDING)
                    ->requiresConfirmation()->modalDescription('Paket langsung aktif untuk agensi ini sesuai permintaan. Pastikan transfer sudah masuk.')
                    ->action(function (SubscriptionRequest $record) {
                        app(Subscriptions::class)->activate($record->tenant, $record->plan, $record->billing_cycle, $record->billing_cycle === 'yearly' ? 12 : 1);
                        Notification::make()->title('Paket diaktifkan')->success()->send();
                    }),
                Action::make('reject')->label('Tolak')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (SubscriptionRequest $r) => $r->status === SubscriptionRequest::PENDING)
                    ->requiresConfirmation()
                    ->action(fn (SubscriptionRequest $record) => $record->update(['status' => SubscriptionRequest::CANCELLED, 'handled_at' => now()])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSubscriptionRequests::route('/')];
    }
}
