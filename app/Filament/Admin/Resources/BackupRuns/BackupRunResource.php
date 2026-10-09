<?php

namespace App\Filament\Admin\Resources\BackupRuns;

use App\Filament\Admin\Resources\BackupRuns\Pages\ListBackupRuns;
use App\Models\BackupRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BackupRunResource extends Resource
{
    protected static ?string $model = BackupRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $modelLabel = 'backup';

    protected static ?string $pluralModelLabel = 'Backup';

    protected static ?string $navigationLabel = 'Backup';

    protected static ?string $slug = 'backup-runs';

    protected static ?int $navigationSort = 8;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')->label('Mulai')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state, BackupRun $r) => $r->label())
                    ->color(fn ($state) => ['ok' => 'success', 'warning' => 'warning', 'failed' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('size_bytes')->label('Ukuran')->formatStateUsing(fn ($state) => number_format($state / 1048576, 1, ',', '.').' MB'),
                TextColumn::make('steps')->label('Langkah')->wrap()
                    ->state(fn (BackupRun $r) => collect($r->steps)->map(fn ($s) => "{$s['label']}: {$s['status']}".($s['note'] ? " ({$s['note']})" : ''))->implode("\n"))
                    ->html()->formatStateUsing(fn ($state) => nl2br(e($state))),
                TextColumn::make('folder')->label('Folder')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('Belum ada backup')
            ->emptyStateDescription('Backup berjalan otomatis tiap 02:00 WIB. Jalankan sekarang di server: php artisan backup:run');
    }

    public static function getPages(): array
    {
        return ['index' => ListBackupRuns::route('/')];
    }
}
