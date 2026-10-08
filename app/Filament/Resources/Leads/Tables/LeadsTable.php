<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Enums\Interest;
use App\Models\Lead;
use App\Support\CurrentTenant;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('next_action_due_at')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn (Lead $record) => collect([$record->phone, $record->property?->name])->filter()->implode(' · ')),
                TextColumn::make('phone')
                    ->label('Telepon')
                    ->searchable()
                    ->copyable()
                    ->visibleFrom('lg')
                    ->toggleable(),
                TextColumn::make('stage.name')
                    ->label('Tahap')
                    ->badge()
                    ->visibleFrom('md')
                    ->color('gray'),
                TextColumn::make('interest')
                    ->label('Minat')
                    ->badge(),
                TextColumn::make('owner.name')
                    ->label('Agen')
                    ->visibleFrom('lg')
                    ->toggleable(),
                TextColumn::make('next_action')
                    ->label('Aksi berikutnya')
                    ->limit(40)
                    ->visibleFrom('lg')
                    ->wrap(),
                TextColumn::make('next_action_due_at')
                    ->label('Tenggat')
                    ->dateTime('j M, H:i')
                    ->sortable()
                    ->color(fn (Lead $record) => $record->isOverdue() && $record->stage?->isOpen() ? 'danger' : null)
                    ->weight(fn (Lead $record) => $record->isOverdue() && $record->stage?->isOpen() ? 'bold' : null),
                TextColumn::make('source.name')
                    ->label('Sumber')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Masuk')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('stage')
                    ->label('Tahap')
                    ->relationship('stage', 'name', fn (Builder $query) => $query->orderBy('position')),
                SelectFilter::make('interest')
                    ->label('Minat')
                    ->options(Interest::class),
                SelectFilter::make('owner_id')
                    ->label('Agen')
                    ->options(fn () => app(CurrentTenant::class)->get()?->activeUsers()->orderBy('name')->pluck('name', 'users.id') ?? [])
                    ->visible(fn () => app(CurrentTenant::class)->roleOf(auth()->user())?->seesAllLeads() ?? false),
                Filter::make('overdue')
                    ->label('Aksi terlambat')
                    ->query(fn (Builder $query) => $query->overdue()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
