<?php

namespace App\Filament\Pages;

use App\Ai\Facts\FactFormat;
use App\Ai\Facts\FactKeys;
use App\Ai\Facts\Facts;
use App\Filament\Concerns\InSettingsAndTools;
use App\Models\AiFact;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Tinjau data AI": facts read from documents wait here until the owner approves them, and facts two sources
 * disagree on (tanah 72 vs 75 m2) are shown side by side for the owner to pick one. Nothing here reaches the AI
 * before it is approved, and a disputed fact is left out of the AI's knowledge until it is decided.
 */
class FactReview extends Page implements HasTable
{
    use InSettingsAndTools;
    use InteractsWithTable;

    protected string $view = 'filament.pages.fact-review';

    protected static ?string $slug = 'tinjau-data-ai';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Tinjau data AI';

    protected static ?string $title = 'Tinjau data AI';

    // Opened from "Pengaturan & Alat" (App\Filament\Support\SettingsCatalog), not from the sidebar.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 3;

    /** @var list<int>|null */
    private ?array $conflictIds = null;

    public static function canAccess(): bool
    {
        return PropertySetup::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = AiFact::query()->where('status', AiFact::PENDING)->count() + count(Facts::conflicts());

        return $count > 0 ? (string) $count : null;
    }

    /** @return list<int> */
    public function conflictIds(): array
    {
        return $this->conflictIds ??= Facts::conflictIds();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => AiFact::query()->where(fn ($q) => $q->where('status', AiFact::PENDING)->orWhereIn('id', $this->conflictIds() ?: [0])))
            ->defaultSort('id')
            ->emptyStateHeading('Tidak ada data yang perlu ditinjau')
            ->emptyStateDescription('Data dari dokumen yang dibaca lewat "Isi properti" muncul di sini sebelum dipakai AI.')
            ->columns([
                TextColumn::make('subject')->label('Tentang')->default('-')
                    ->formatStateUsing(fn (AiFact $record) => $record->subject ? 'Tipe '.preg_replace('/^tipe\s+/iu', '', $record->subject) : 'Proyek'),
                TextColumn::make('key')->label('Data')->formatStateUsing(fn (string $state) => FactKeys::label($state)),
                TextColumn::make('value')->label('Isi')->formatStateUsing(fn (AiFact $record) => FactFormat::show($record->key, $record->value))->wrap()->weight('bold'),
                TextColumn::make('source')->label('Sumber')->formatStateUsing(fn (AiFact $record) => $record->source === AiFact::WIZARD ? 'Isi properti' : 'Dokumen: '.$record->source_name)->wrap(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (AiFact $record) => in_array($record->getKey(), $this->conflictIds(), true) ? 'Bertentangan' : ($record->status === AiFact::APPROVED ? 'Dipakai' : 'Menunggu'))
                    ->color(fn (AiFact $record) => in_array($record->getKey(), $this->conflictIds(), true) ? 'danger' : ($record->status === AiFact::APPROVED ? 'success' : 'warning')),
            ])
            ->recordActions([
                Action::make('choose')->label('Pakai nilai ini')->icon(Heroicon::OutlinedCheckCircle)->color('primary')
                    ->visible(fn (AiFact $record) => in_array($record->getKey(), $this->conflictIds(), true))
                    ->requiresConfirmation()->modalHeading('Pakai nilai ini?')
                    ->modalDescription(fn (AiFact $record) => 'Nilai lain untuk '.FactKeys::label($record->key).' ditolak, dan AI memakai '.FactFormat::show($record->key, $record->value).'.')
                    ->action(fn (AiFact $record) => $this->done(fn () => Facts::choose($record), 'Nilai dipakai')),
                Action::make('approve')->label('Setujui')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (AiFact $record) => $record->status === AiFact::PENDING && ! in_array($record->getKey(), $this->conflictIds(), true))
                    ->action(fn (AiFact $record) => $this->done(fn () => Facts::approve($record), 'Data disetujui dan dipakai AI')),
                Action::make('reject')->label('Tolak')->icon(Heroicon::OutlinedXMark)->color('gray')
                    ->action(fn (AiFact $record) => $this->done(fn () => Facts::reject($record), 'Data ditolak')),
            ])
            ->toolbarActions([
                BulkAction::make('approveAll')->label('Setujui yang dipilih')->icon(Heroicon::OutlinedCheck)
                    ->action(fn (Collection $records) => $this->done(function () use ($records) {
                        $records->filter(fn (AiFact $f) => $f->status === AiFact::PENDING && ! in_array($f->getKey(), $this->conflictIds(), true))
                            ->each(fn (AiFact $f) => Facts::approve($f));
                    }, 'Data disetujui')),
            ]);
    }

    private function done(callable $change, string $title): void
    {
        $change();
        $this->conflictIds = null;
        Notification::make()->title($title)->success()->send();
    }
}
