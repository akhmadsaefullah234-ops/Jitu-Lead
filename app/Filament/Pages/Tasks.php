<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every open lead's next action in one list, latest-due first, so an agent
 * starts the day with what is late and what is due.
 */
class Tasks extends Page implements HasActions, HasTable
{
    use InteractsWithActions, InteractsWithTable;

    protected string $view = 'filament.pages.tasks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Tugas';

    protected static ?string $title = 'Tugas tindak lanjut';

    protected static ?int $navigationSort = 4;

    public static function getNavigationBadge(): ?string
    {
        $late = LeadResource::onlyVisibleLeads(Lead::query())->overdue()->count();

        return $late > 0 ? (string) $late : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => LeadResource::onlyVisibleLeads(Lead::query())->open()->whereNotNull('next_action')->with(['stage', 'owner']))
            ->defaultSort('next_action_due_at')
            ->paginated([10, 25])
            ->emptyStateHeading('Semua tindak lanjut sudah beres')
            ->columns([
                TextColumn::make('next_action')->label('Tindak lanjut')->weight('bold')->wrap()
                    ->description(fn (Lead $r) => $r->name.' · '.$r->stage?->name),
                TextColumn::make('next_action_due_at')->label('Batas')->dateTime('d M H:i')->sortable()
                    ->color(fn (Lead $r) => $r->isOverdue() ? 'danger' : null)
                    ->description(fn (Lead $r) => $r->next_action_due_at?->diffForHumans()),
                TextColumn::make('owner.name')->label('Agen')->toggleable(),
            ])
            ->filters([
                Filter::make('late')->label('Terlambat')->query(fn (Builder $q) => $q->where('next_action_due_at', '<', now())),
                Filter::make('today')->label('Hari ini')->query(fn (Builder $q) => $q->whereBetween('next_action_due_at', [now()->startOfDay(), now()->endOfDay()])),
            ])
            ->recordActions([
                Action::make('done')->label('Selesai')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->modalHeading('Catat hasil dan jadwalkan berikutnya')
                    ->schema([
                        Textarea::make('result')->label('Hasil')->required()->rows(2)->maxLength(1000),
                        TextInput::make('next_action')->label('Tindak lanjut berikutnya')->required()->maxLength(160),
                        DateTimePicker::make('due')->label('Batas waktu')->required()->native(false)->seconds(false)->minDate(now()->subMinute()),
                    ])
                    ->action(function (Lead $record, array $data) {
                        $record->activities()->create([
                            'user_id' => auth()->id(), 'type' => 'task_done',
                            'body' => "Selesai: {$record->next_action}. Hasil: {$data['result']}",
                        ]);
                        $record->update(['next_action' => $data['next_action'], 'next_action_due_at' => $data['due']]);
                        Notification::make()->title('Tindak lanjut dicatat')->success()->send();
                    }),
                Action::make('open')->label('Buka lead')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                    ->url(fn (Lead $r) => LeadResource::getUrl('edit', ['record' => $r])),
            ]);
    }
}
