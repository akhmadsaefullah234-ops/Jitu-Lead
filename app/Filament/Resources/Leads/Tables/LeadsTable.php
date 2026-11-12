<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Actions\ReassignLead;
use App\Enums\Interest;
use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Filament\Support\MobileDetails;
use App\Filament\Support\ShipmentActions;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Stage;
use App\Reports\DashboardData;
use App\Reports\LeadOrigin;
use App\Shipping\Shipments\AutoShipments;
use App\Support\CurrentTenant;
use App\Support\Industry;
use App\Support\Period;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LeadsTable
{
    /** The ad a lead came from: its title, else its id. */
    public const AD = "coalesce(nullif(leads.ad_title, ''), nullif(leads.ad_id, ''))";

    /** The "Tanggal masuk" filter's period, or null for all time. */
    public static function period(array $data): ?Period
    {
        return Period::optional($data['periode'] ?? null, $data['dari'] ?? null, $data['sampai'] ?? null);
    }

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
                    ->description(fn (Lead $record) => collect([$record->spamReasons() ? '⚠ Dicurigai spam' : null, $record->phone, LeadForm::showsIndustryFields() ? $record->property?->name : null])->filter()->implode(' · ')),
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
                MobileDetails::secondary(TextColumn::make('owner.name'))
                    ->label('Agen')
                    ->visibleFrom('lg')
                    ->toggleable()
                    ->description(fn (Lead $record) => self::isUnattended($record) ? 'Belum ditanggapi' : null)
                    ->color(fn (Lead $record) => self::isUnattended($record) ? 'danger' : null),
                MobileDetails::secondary(TextColumn::make('next_action'))
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
                MobileDetails::secondary(TextColumn::make('source.name'))
                    ->label('Sumber')
                    ->toggleable(isToggledHiddenByDefault: true),
                MobileDetails::secondary(TextColumn::make('resi_check'))
                    ->label('Resi')
                    ->state(fn (Lead $record) => self::resiCheck($record))
                    ->color(fn (?string $state) => $state === 'Siap resi' ? 'success' : 'warning')
                    ->wrap()
                    ->visible(fn () => ShipmentActions::available())
                    ->toggleable(isToggledHiddenByDefault: true),
                MobileDetails::secondary(TextColumn::make('created_at'))
                    ->label('Masuk')
                    ->since()
                    ->sortable()
                    ->toggleable(),
                MobileDetails::toggle(),
            ])
            ->filtersFormColumns(['default' => 1, 'md' => 2])
            ->filtersFormWidth(Width::ThreeExtraLarge)
            ->filters([
                Filter::make('masuk')
                    ->schema(Period::fields(all: true, label: 'Tanggal masuk'))
                    ->columnSpanFull()
                    ->query(fn (Builder $query, array $data) => ($p = self::period($data)) ? $p->apply($query, 'leads.created_at') : $query)
                    ->indicateUsing(fn (array $data) => ($p = self::period($data)) ? 'Masuk: '.$p->label() : null),
                SelectFilter::make('produk')
                    ->label(ucfirst(Industry::t('catalog_item')))
                    ->visible(fn () => LeadForm::showsIndustryFields())
                    ->options(fn () => ['none' => 'Tanpa '.Industry::t('catalog_item')] + Property::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'none' => $query->whereNull('leads.property_id'),
                        default => $query->where('leads.property_id', (int) $data['value']),
                    }),
                SelectFilter::make('sumber')
                    ->label('Asal lead')
                    ->options(LeadOrigin::LABELS)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? LeadOrigin::apply($query, $data['value']) : $query),
                SelectFilter::make('iklan')
                    ->label('Iklan')
                    ->options(fn () => Lead::query()->selectRaw(self::AD.' as ad')->whereRaw(self::AD.' is not null')->distinct()->orderBy('ad')->limit(200)->pluck('ad', 'ad')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->whereRaw(self::AD.' = ?', [$data['value']]) : $query),
                Filter::make('masuk_tahap')
                    ->schema([
                        Select::make('tahap')->label('Masuk tahap')->placeholder('Pilih tahap')
                            ->options(fn () => Stage::query()->orderBy('position')->pluck('name', 'id')),
                        ...Period::fields(label: 'Pada periode'),
                    ])
                    ->columnSpanFull()
                    ->query(fn (Builder $query, array $data) => filled($data['tahap'] ?? null)
                        ? DashboardData::enteredIn($query, Period::make($data['periode'] ?? null, $data['dari'] ?? null, $data['sampai'] ?? null), [(int) $data['tahap']])
                        : $query)
                    ->indicateUsing(fn (array $data) => filled($data['tahap'] ?? null)
                        ? 'Masuk '.(Stage::query()->find($data['tahap'])?->name ?? 'tahap').': '.Period::make($data['periode'] ?? null, $data['dari'] ?? null, $data['sampai'] ?? null)->label()
                        : null),
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
                SelectFilter::make('resi')
                    ->label('Kesiapan resi')
                    ->options(['ready' => 'Siap resi', 'incomplete' => 'Perlu dilengkapi'])
                    ->visible(fn () => ShipmentActions::available())
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'ready' => AutoShipments::scopeReady($query),
                        'incomplete' => AutoShipments::scopeReady($query, false),
                        default => $query,
                    }),
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
                    ShipmentActions::bulkCreate(),
                    ShipmentActions::bulkLabels(),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    /** "Siap resi", what is missing for one, or nothing for a lead without an order or with a resi already. */
    private static function resiCheck(Lead $lead): ?string
    {
        if (filled($lead->tracking_number) || ($lead->property_id === null && blank($lead->order_items))) {
            return null;
        }

        if (in_array($lead->auto_shipment_state, [AutoShipments::FAILED, AutoShipments::WAITING_BALANCE], true)) {
            return $lead->auto_shipment_note;
        }

        $problems = app(AutoShipments::class)->problems($lead);

        return $problems === [] ? 'Siap resi' : 'Perlu dilengkapi: '.implode(', ', $problems);
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
