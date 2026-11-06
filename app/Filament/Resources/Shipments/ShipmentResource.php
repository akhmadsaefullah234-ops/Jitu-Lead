<?php

namespace App\Filament\Resources\Shipments;

use App\Filament\Pages\Inbox;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Filament\Support\ShipmentActions;
use App\Models\Property;
use App\Models\Shipment;
use App\Shipping\Carriers\ShipmentStatus;
use App\Shipping\Shipments\LabelSheet;
use App\Shipping\Shipments\LabelStore;
use App\Support\CurrentTenant;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * "Pengiriman" (main menu, shops with a shipping provider): every parcel, by what it needs next. Unprinted first,
 * then printed and waiting for the courier, on the way, needing action, delivered and cancelled. Labels are
 * printed from here in one PDF (optionally after a packing list); each run is kept to download again. Admins
 * and team leaders see every parcel; an agent only those of their own leads.
 */
class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;

    protected static ?string $slug = 'kiriman';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $modelLabel = 'kiriman';

    protected static ?string $pluralModelLabel = 'kiriman';

    protected static ?string $navigationLabel = 'Pengiriman';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return ShipmentActions::available();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('lead_id', LeadResource::getEloquentQuery()->select('leads.id'));
    }

    public static function getNavigationBadge(): ?string
    {
        $unprinted = static::getEloquentQuery()->tab('unprinted')->count();

        return $unprinted > 0 ? (string) $unprinted : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Resi belum dicetak';
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        $rp = fn (?int $v) => 'Rp'.number_format((int) $v, 0, ',', '.');
        $tz = fn () => app(CurrentTenant::class)->get()?->timezone ?: 'Asia/Jakarta';

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lead:id,name,phone,variant,quantity,order_items,property_id,owner_id', 'lead.property:id,name', 'printer:id,name']))
            ->columns([
                TextColumn::make('created_at')->label('Tanggal resi')->dateTime('d M H:i')->timezone($tz())->sortable(),
                TextColumn::make('lead.name')->label('Pembeli')->searchable(['name', 'phone'])->weight('bold')
                    ->url(fn (Shipment $r) => $r->lead ? Inbox::getUrl(['lead' => $r->lead_id]) : null)
                    ->description(fn (Shipment $r) => $r->lead ? new HtmlString('<a href="'.e(LeadResource::getUrl('edit', ['record' => $r->lead_id])).'" style="text-decoration:underline">Buka lead</a>') : null),
                TextColumn::make('items')->label('Produk')->state(fn (Shipment $r) => self::items($r))->wrap(),
                TextColumn::make('courier')->label('Kurir')->toggleable(),
                TextColumn::make('awb')->label('Nomor resi')->searchable()->copyable()->copyMessage('Nomor resi disalin')->placeholder('-'),
                TextColumn::make('is_cod')->label('Bayar')->state(fn (Shipment $r) => $r->is_cod ? 'COD '.$rp($r->cod_amount) : 'Transfer')
                    ->badge()->color(fn (Shipment $r) => $r->is_cod ? 'warning' : 'gray'),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Shipment $r) => self::status($r))
                    ->color(fn (Shipment $r) => match (true) {
                        $r->state === Shipment::FAILED, in_array($r->status, [ShipmentStatus::DELIVERY_FAILED, ShipmentStatus::RETURNING, ShipmentStatus::RETURNED], true) => 'danger',
                        $r->status === ShipmentStatus::DELIVERED => 'success',
                        $r->state === Shipment::CANCELLED => 'gray',
                        default => 'info',
                    })
                    ->description(fn (Shipment $r) => ($t = self::statusTime($r)) ? $t->timezone($tz())->format('d M H:i') : null),
                TextColumn::make('pickup')->label('Jadwal jemput')->toggleable()
                    ->state(fn (Shipment $r) => ($at = data_get($r->request, 'pickup_time')) ? Carbon::parse($at)->timezone($tz())->format('d M H:i')
                        : (data_get($r->request, 'shipment_type') === 'DROP' ? 'Antar ke agen' : null))->placeholder('-'),
                TextColumn::make('printed_at')->label('Dicetak')->dateTime('d M H:i')->timezone($tz())->placeholder('Belum')->sortable()
                    ->description(fn (Shipment $r) => $r->printed_at ? trim(($r->printer?->name ?? '').($r->print_count > 1 ? ' · '.$r->print_count.'x' : '')) ?: null : null),
            ])
            ->filters([
                Filter::make('tanggal')->schema([
                    DatePicker::make('dari')->label('Resi dari'),
                    DatePicker::make('sampai')->label('Sampai'),
                ])->columns(2)->query(fn (Builder $q, array $data) => $q
                    ->when($data['dari'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', CarbonImmutable::parse($d, $tz())->startOfDay()->utc()))
                    ->when($data['sampai'] ?? null, fn ($q, $d) => $q->where('created_at', '<', CarbonImmutable::parse($d, $tz())->addDay()->startOfDay()->utc()))),
                SelectFilter::make('courier')->label('Kurir')
                    ->options(fn () => Shipment::query()->whereNotNull('courier')->distinct()->orderBy('courier')->pluck('courier', 'courier')->all()),
                TernaryFilter::make('is_cod')->label('Cara bayar')->trueLabel('COD')->falseLabel('Transfer'),
                SelectFilter::make('produk')->label('Produk')
                    ->options(fn () => Property::query()->orderBy('name')->pluck('name', 'id')->all())->searchable()
                    ->query(fn (Builder $q, array $data) => filled($data['value'] ?? null) ? $q->whereHas('lead', fn ($l) => $l->where('property_id', (int) $data['value'])) : $q),
            ])
            ->recordActions([
                Action::make('print')->label(fn (Shipment $r) => $r->printed_at ? 'Cetak ulang' : 'Cetak')->icon(Heroicon::OutlinedPrinter)->color('gray')
                    ->visible(fn (Shipment $r) => $r->printable())
                    ->action(fn (Shipment $record, $livewire) => self::printNow(collect([$record]), $livewire, false)),
                Action::make('retry')->label('Coba lagi')->icon(Heroicon::OutlinedArrowPath)->color('warning')
                    ->visible(fn (Shipment $r) => $r->retryable())
                    ->requiresConfirmation()->modalHeading('Coba lagi kiriman yang gagal?')
                    ->modalDescription(fn (Shipment $r) => ShipmentActions::retryDescription($r))->modalSubmitActionLabel('Coba lagi')
                    ->action(fn (Shipment $record) => ShipmentActions::retryShipment($record)),
                Action::make('retryLabel')->label('Ambil ulang label')->icon(Heroicon::OutlinedArrowPath)->color('warning')
                    ->visible(fn (Shipment $r) => $r->printable() && $r->label_path === null && $r->label_failed_at !== null)
                    ->action(fn (Shipment $record) => app(LabelStore::class)->save($record)
                        ? Notification::make()->title('Label tersimpan')->body('Kiriman pindah ke "Belum dicetak".')->success()->send()
                        : Notification::make()->title('Label masih belum tersedia')->body('Coba lagi beberapa saat lagi, atau cetak dari dashboard penyedia.')->warning()->send()),
                Action::make('markPrinted')->label('Tandai sudah dicetak')->icon(Heroicon::OutlinedCheck)->color('gray')
                    ->visible(fn (Shipment $r) => $r->printable() && $r->printed_at === null)
                    ->action(fn (Shipment $record) => self::mark(collect([$record]), true)),
                Action::make('markUnprinted')->label('Tandai belum dicetak')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                    ->visible(fn (Shipment $r) => $r->printable() && $r->printed_at !== null)
                    ->action(fn (Shipment $record) => self::mark(collect([$record]), false)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('printSelected')->label('Cetak label terpilih')->icon(Heroicon::OutlinedPrinter)
                        ->schema([self::packingToggle()])
                        ->action(fn (Collection $records, array $data, $livewire) => self::printNow($records->sortBy('created_at')->values(), $livewire, (bool) ($data['packing_list'] ?? false)))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('markPrintedBulk')->label('Tandai sudah dicetak')->icon(Heroicon::OutlinedCheck)
                        ->action(fn (Collection $records) => self::mark($records, true))->deselectRecordsAfterCompletion(),
                    BulkAction::make('markUnprintedBulk')->label('Tandai belum dicetak')->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->action(fn (Collection $records) => self::mark($records, false))->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->emptyStateHeading('Tidak ada kiriman di sini')
            ->paginated([25, 50, 100]);
    }

    public static function packingToggle(): Toggle
    {
        return Toggle::make('packing_list')->label('Lembar pertama: daftar pengemasan')->default(true)
            ->helperText('Nama pembeli, produk, varian, jumlah, kurir, resi dan COD tiap paket, untuk cek sebelum diserahkan ke kurir.');
    }

    /** One PDF of the parcels' labels, opened in a new tab; the parcels printed move to "Sudah dicetak". */
    public static function printNow(Collection $shipments, $livewire, bool $packingList): void
    {
        $sheet = app(LabelSheet::class)->print($shipments->filter(fn (Shipment $s) => $s->printable())->values(), auth()->user(), $packingList);
        $printed = count($sheet['printed']);
        $skipped = $sheet['skipped'];
        $lines = array_map(fn ($s) => '• '.e($s['name']).': '.e($s['reason']), array_slice($skipped, 0, 20));

        if ($sheet['url'] !== null) {
            array_unshift($lines, '<a href="'.e($sheet['url']).'" target="_blank" style="text-decoration:underline">Buka PDF label</a>');
            $livewire->js('window.open('.json_encode($sheet['url']).', "_blank")');
        }

        Notification::make()->title("{$printed} label dicetak".($skipped !== [] ? ' · '.count($skipped).' dilewati' : ''))
            ->body($lines === [] ? null : new HtmlString(implode('<br>', $lines)))
            ->{$printed === 0 ? 'warning' : ($skipped === [] ? 'success' : 'warning')}()->persistent()->send();
    }

    public static function mark(Collection $shipments, bool $printed): void
    {
        $count = 0;

        foreach ($shipments as $shipment) {
            if (! $shipment->printable()) {
                continue;
            }

            $shipment->forceFill($printed
                ? ['printed_at' => $shipment->printed_at ?? now(), 'printed_by' => $shipment->printed_by ?? auth()->id()]
                : ['printed_at' => null, 'printed_by' => null])->save();
            $count++;
        }

        Notification::make()->title($count.' kiriman ditandai '.($printed ? 'sudah' : 'belum').' dicetak')->success()->send();
    }

    /** "Tas Badminton · Hitam · 2" (several products: each with its quantity). */
    public static function items(Shipment $shipment): string
    {
        $lead = $shipment->lead;

        if ($lead === null) {
            return '-';
        }

        $items = collect((array) $lead->order_items)->filter(fn ($i) => filled($i['name'] ?? null));

        if ($items->isEmpty()) {
            return implode(' · ', array_filter([$lead->property?->name, $lead->variant, $lead->quantity ? (string) $lead->quantity : null])) ?: '-';
        }

        return $items->map(fn ($i) => implode(' · ', array_filter([$i['name'], $i['variant'] ?? null, (string) ($i['quantity'] ?? 1)])))->implode('; ');
    }

    public static function status(Shipment $shipment): string
    {
        return match (true) {
            $shipment->state === Shipment::FAILED => 'Resi gagal dibuat',
            $shipment->state === Shipment::PENDING, $shipment->state === Shipment::ORDERED => 'Sedang dibuat',
            $shipment->state === Shipment::CANCELLED => 'Dibatalkan',
            $shipment->printable() && $shipment->label_path === null && $shipment->label_failed_at !== null && $shipment->printed_at === null => 'Label belum tersedia',
            default => ShipmentStatus::label($shipment->status ?? ShipmentStatus::AWB_CREATED),
        };
    }

    private static function statusTime(Shipment $shipment): ?Carbon
    {
        $last = collect((array) $shipment->events)->last();

        return filled($last['at'] ?? null) ? Carbon::parse($last['at']) : ($shipment->tracked_at ?? $shipment->updated_at);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShipments::route('/'),
        ];
    }
}
