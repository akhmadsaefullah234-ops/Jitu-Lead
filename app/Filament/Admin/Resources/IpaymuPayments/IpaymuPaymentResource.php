<?php

namespace App\Filament\Admin\Resources\IpaymuPayments;

use App\Filament\Admin\Resources\IpaymuPayments\Pages\ListIpaymuPayments;
use App\Models\IpaymuPayment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Read-only list of iPaymu payments so the owner can check what came in. */
class IpaymuPaymentResource extends Resource
{
    protected static ?string $model = IpaymuPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static ?string $modelLabel = 'transaksi iPaymu';

    protected static ?string $pluralModelLabel = 'Transaksi iPaymu';

    protected static ?string $navigationLabel = 'Transaksi iPaymu';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['request', 'tenant']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('tenant.name')->label('Agensi')->searchable(),
                TextColumn::make('request.plan')->label('Paket')->formatStateUsing(fn ($state) => config("plans.plans.$state.name", $state)),
                TextColumn::make('request.billing_cycle')->label('Siklus')->formatStateUsing(fn ($state) => $state === 'yearly' ? 'Tahunan' : 'Bulanan'),
                TextColumn::make('amount')->label('Harga paket')->money('IDR', locale: 'id'),
                TextColumn::make('total_paid')->label('Dibayar pembeli')->money('IDR', locale: 'id')->placeholder('-'),
                TextColumn::make('fee')->label('Biaya')->money('IDR', locale: 'id')->placeholder('-'),
                TextColumn::make('paid_off')->label('Diterima')->money('IDR', locale: 'id')->placeholder('-'),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state, IpaymuPayment $record) => $record->label())
                    ->color(fn ($state) => ['pending' => 'warning', 'paid' => 'success', 'failed' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('trx_id')->label('ID transaksi')->placeholder('-')->searchable()->copyable(),
                TextColumn::make('reference_id')->label('Referensi')->searchable(),
                TextColumn::make('channel')->label('Metode')->placeholder('-'),
                TextColumn::make('created_at')->label('Dibuat')->dateTime('d M Y H:i')->timezone(config('app.timezone')),
                TextColumn::make('paid_at')->label('Dibayar')->dateTime('d M Y H:i')->timezone(config('app.timezone'))->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')->options(IpaymuPayment::LABELS),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListIpaymuPayments::route('/')];
    }
}
