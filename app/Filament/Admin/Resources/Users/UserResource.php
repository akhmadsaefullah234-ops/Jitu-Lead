<?php

namespace App\Filament\Admin\Resources\Users;

use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Password;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'pengguna';

    protected static ?string $pluralModelLabel = 'Pengguna';

    protected static ?string $navigationLabel = 'Pengguna';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tenants'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('tenants')->label('Agensi')->state(fn (User $u) => $u->tenants->pluck('name')->implode(', '))->placeholder('-'),
                IconColumn::make('email_verified_at')->label('Email terverifikasi')->boolean()->state(fn (User $u) => $u->email_verified_at !== null),
                IconColumn::make('is_super_admin')->label('Super admin')->boolean(),
                TextColumn::make('created_at')->label('Daftar')->dateTime('d M Y')->sortable(),
            ])
            ->filters([TernaryFilter::make('verified')->label('Email terverifikasi')->queries(
                true: fn ($q) => $q->whereNotNull('email_verified_at'),
                false: fn ($q) => $q->whereNull('email_verified_at'),
            )])
            ->recordActions([
                Action::make('verify')->label('Tandai terverifikasi')->icon(Heroicon::OutlinedCheckCircle)
                    ->visible(fn (User $u) => $u->email_verified_at === null)
                    ->action(function (User $record) {
                        $record->forceFill(['email_verified_at' => now()])->save();
                        Notification::make()->title('Email ditandai terverifikasi')->success()->send();
                    }),
                Action::make('reset')->label('Kirim tautan reset sandi')->icon(Heroicon::OutlinedKey)->requiresConfirmation()
                    ->action(function (User $record) {
                        $status = Password::sendResetLink(['email' => $record->email]);
                        Notification::make()->title($status === Password::RESET_LINK_SENT ? 'Tautan dikirim' : 'Gagal mengirim (cek pengaturan email)')->{$status === Password::RESET_LINK_SENT ? 'success' : 'danger'}()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/')];
    }
}
