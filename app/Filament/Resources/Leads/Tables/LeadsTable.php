<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Actions\ReassignLead;
use App\Enums\Interest;
use App\Models\Lead;
use App\Support\CurrentTenant;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Newest first, so a lead just added by hand, from a form or from WhatsApp is on the first page.
            // Sorting by "Tenggat" is one click away, and the Pipeline and Tugas pages order by urgency.
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(25)
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
                    ->toggleable()
                    ->description(fn (Lead $record) => self::isUnattended($record) ? 'Belum ditanggapi' : null)
                    ->color(fn (Lead $record) => self::isUnattended($record) ? 'danger' : null),
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
                    ->toggleable(),
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
                Filter::make('unattended')
                    ->label('Belum ditanggapi')
                    ->query(fn (Builder $query) => $query->unattended(self::waitMinutes()))
                    ->visible(fn () => self::canMove()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('move')
                    ->label('Pindahkan')
                    ->icon('heroicon-o-arrows-right-left')
                    ->visible(fn (Lead $record) => self::canMove() && $record->trashed() === false)
                    ->modalHeading('Pindahkan lead ke agen lain')
                    ->schema([
                        Select::make('to')->label('Pindahkan ke')->placeholder('Agen berikutnya (otomatis, sesuai porsi)')
                            ->options(fn (Lead $record) => app(CurrentTenant::class)->get()?->activeUsers()->where('users.id', '!=', $record->owner_id)->orderBy('name')->pluck('name', 'users.id') ?? []),
                    ])
                    ->action(fn (Lead $record, array $data) => self::move(collect([$record]), $data['to'] ?? null)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('moveNext')
                        ->label('Pindahkan ke agen berikutnya')
                        ->icon('heroicon-o-arrows-right-left')
                        ->visible(fn () => self::canMove())
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => self::move($records, null)),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    private static function waitMinutes(): int
    {
        return app(CurrentTenant::class)->get()?->leadWaitMinutes() ?? 30;
    }

    private static function canMove(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user())?->seesAllLeads() ?? false;
    }

    /** Whether the owner has let this lead sit past the agency's waiting time (looked up once per page load). */
    private static function isUnattended(Lead $lead): bool
    {
        if (! self::canMove()) {
            return false;
        }

        // Kept on the container, not in a static, so it lives for one request only.
        $ids = app()->bound('jitu.unattended') ? app('jitu.unattended') : tap(
            Lead::query()->unattended(self::waitMinutes())->pluck('id')->all(),
            fn (array $found) => app()->instance('jitu.unattended', $found),
        );

        return in_array($lead->getKey(), $ids, true);
    }

    /** @param  Collection<int, Lead>  $leads */
    private static function move(Collection $leads, int|string|null $to): void
    {
        $target = $to ? app(CurrentTenant::class)->get()?->activeUsers()->where('users.id', $to)->first() : null;
        $moved = 0;

        foreach ($leads as $lead) {
            if (auth()->user()?->can('reassign', $lead) && app(ReassignLead::class)($lead, by: auth()->user(), to: $target) !== null) {
                $moved++;
            }
        }

        app()->forgetInstance('jitu.unattended');

        Notification::make()
            ->title($moved > 0 ? "{$moved} lead dipindahkan" : 'Tidak ada agen lain yang bisa menerima lead ini')
            ->color($moved > 0 ? 'success' : 'warning')
            ->send();
    }
}
