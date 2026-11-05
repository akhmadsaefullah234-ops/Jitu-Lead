<?php

namespace App\Filament\Support;

use App\Automation\RetailStages;
use App\Models\Lead;
use App\Models\Shipment;
use App\Shipping\Carriers\CarrierException;
use App\Shipping\Carriers\CarrierLocation;
use App\Shipping\Carriers\CarrierRate;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Shipments\LabelSheet;
use App\Shipping\Shipments\ShipmentDraft;
use App\Shipping\Shipments\Shipments;
use App\Support\CurrentTenant;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

/**
 * "Buat resi", "Cetak label", "Batalkan kiriman" and "Lacak paket", the same in the Inbox ⋯ menu and on the
 * lead page, plus the bulk actions on the lead list (stage Dikemas & dikirim). All hidden while the agency has no
 * provider configured (Pengaturan > Pengiriman). They only talk to App\Shipping\Shipments.
 */
class ShipmentActions
{
    /** @var array<string, ShipmentDraft> one preview per lead and choice for one request */
    private static array $drafts = [];

    public static function available(): bool
    {
        return Carriers::configured(app(CurrentTenant::class)->get());
    }

    private static function draft(Lead $lead, ?string $location, ?string $rate): ShipmentDraft
    {
        $key = $lead->getKey().'|'.$location.'|'.$rate;

        return self::$drafts[$key] ??= app(Shipments::class)->draft($lead, ['location_id' => $location, 'rate_code' => $rate]);
    }

    /** @param  Closure(): ?Lead  $lead */
    public static function create(Closure $lead, string $name = 'createShipment'): Action
    {
        return Action::make($name)->label('Buat resi')->icon(Heroicon::OutlinedTruck)->color('gray')
            ->visible(fn () => self::available() && ($l = $lead()) !== null && ! $l->trashed() && Shipment::latestFor($l) === null)
            ->modalHeading('Buat resi otomatis')
            ->modalDescription('Periksa dulu sebelum dikirim ke penyedia pengiriman. Resi yang terbit dikirim ke pembeli lewat WhatsApp seperti biasa.')
            ->modalSubmitActionLabel('Buat resi')
            ->schema([
                Select::make('location_id')->label('Kelurahan tujuan')->live()
                    ->options(fn () => collect(self::draft($lead(), null, null)->candidates)->mapWithKeys(fn (CarrierLocation $l) => [$l->id => $l->label()])->all())
                    ->default(fn () => self::draft($lead(), null, null)->destination?->id)
                    ->visible(fn () => count(self::draft($lead(), null, null)->candidates) > 1 || (self::draft($lead(), null, null)->destination === null && self::draft($lead(), null, null)->candidates !== [])),
                Select::make('rate_code')->label('Kurir dan layanan')->live()
                    ->options(fn (Get $get) => collect(self::draft($lead(), $get('location_id'), null)->rates)
                        ->mapWithKeys(fn (CarrierRate $r) => [$r->code => $r->name().' · Rp'.number_format($r->price, 0, ',', '.').($r->etaMax ? " · {$r->etaMin}-{$r->etaMax} hari" : '')])->all())
                    ->default(fn () => self::draft($lead(), null, null)->rate?->code)
                    ->visible(fn (Get $get) => self::draft($lead(), $get('location_id'), null)->rates !== []),
                Text::make(fn (Get $get) => self::preview(self::draft($lead(), $get('location_id'), $get('rate_code'))))->columnSpanFull(),
            ])
            ->action(function (array $data, Action $action) use ($lead) {
                $record = $lead();
                $draft = app(Shipments::class)->draft($record, ['location_id' => $data['location_id'] ?? null, 'rate_code' => $data['rate_code'] ?? null]);

                if (! $draft->ok()) {
                    Notification::make()->title('Resi belum bisa dibuat')->body(implode(' ', $draft->problems))->danger()->send();
                    $action->halt();
                }

                try {
                    [$shipment, $new] = app(Shipments::class)->book($record, $draft, auth()->user());
                } catch (CarrierException $e) {
                    Notification::make()->title('Resi belum bisa dibuat')->body($e->getMessage())->danger()->send();

                    return;
                }

                $shipment->refresh();
                Notification::make()->title(match (true) {
                    ! $new => 'Kiriman ini sudah dibuat sebelumnya ('.($shipment->awb ?: $shipment->client_order_no).').',
                    $shipment->state === Shipment::SHIPPED => 'Resi '.$shipment->awb.' terbit.',
                    $shipment->state === Shipment::FAILED => 'Kiriman gagal dibuat: '.$shipment->error,
                    default => 'Kiriman sedang dibuat. Resi muncul di lead begitu terbit.',
                })->{$shipment->state === Shipment::FAILED ? 'danger' : 'success'}()->send();
            });
    }

    public static function preview(ShipmentDraft $draft): HtmlString
    {
        if ($draft->problems !== []) {
            return new HtmlString('<div style="color:var(--danger-600);display:grid;gap:.25rem">'.implode('', array_map(fn ($p) => '<div>• '.e($p).'</div>', $draft->problems)).'</div>');
        }

        return new HtmlString('<div style="white-space:pre-line;padding:.6rem .8rem;border-radius:.5rem;background:var(--gray-50);border:1px solid var(--gray-200)">'.e($draft->summary()).'</div>');
    }

    /** @param  Closure(): ?Lead  $lead */
    public static function label(Closure $lead, string $name = 'shipmentLabel'): Action
    {
        return Action::make($name)->label('Cetak label')->icon(Heroicon::OutlinedPrinter)->color('gray')
            ->visible(fn () => self::available() && ($l = $lead()) !== null && filled(Shipment::latestFor($l)?->awb))
            ->action(function ($livewire) use ($lead) {
                try {
                    $url = app(Shipments::class)->label(Shipment::latestFor($lead()));
                } catch (CarrierException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                $livewire->js('window.open('.json_encode($url).', "_blank")');
            });
    }

    /** @param  Closure(): ?Lead  $lead */
    public static function cancel(Closure $lead, string $name = 'cancelShipment'): Action
    {
        return Action::make($name)->label('Batalkan kiriman')->icon(Heroicon::OutlinedXCircle)->color('danger')
            ->visible(fn () => self::available() && ($l = $lead()) !== null && (Shipment::latestFor($l)?->cancellable() ?? false))
            ->requiresConfirmation()->modalHeading('Batalkan kiriman?')
            ->modalDescription('Hanya bisa sebelum paket diambil kurir. Resi lama tidak berlaku lagi; buat resi baru bila perlu.')
            ->action(function () use ($lead) {
                try {
                    app(Shipments::class)->cancel(Shipment::latestFor($lead()), auth()->user());
                } catch (CarrierException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Kiriman dibatalkan')->success()->send();
            });
    }

    /** @param  Closure(): ?Lead  $lead */
    public static function track(Closure $lead, string $name = 'trackShipment'): Action
    {
        return Action::make($name)->label('Lacak paket')->icon(Heroicon::OutlinedMapPin)->color('gray')
            ->visible(fn () => self::available() && ($l = $lead()) !== null && filled(Shipment::latestFor($l)?->awb))
            ->modalHeading('Perjalanan paket')->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
            ->mountUsing(function () use ($lead) {
                $shipment = Shipment::latestFor($lead());

                if ($shipment && ! $shipment->isFinal() && ($shipment->tracked_at === null || $shipment->tracked_at->lt(now()->subMinutes(10)))) {
                    try {
                        app(Shipments::class)->refresh($shipment);
                    } catch (CarrierException $e) {
                        Notification::make()->title($e->getMessage())->warning()->send();
                    }
                }
            })
            ->schema([Text::make(fn () => ShipmentTimeline::html(Shipment::latestFor($lead())))]);
    }

    /** Bulk "Buat resi" for leads in Dikemas & dikirim: each is checked; the ones that pass are queued. */
    public static function bulkCreate(): BulkAction
    {
        return BulkAction::make('bulkCreateShipments')->label('Buat resi')->icon(Heroicon::OutlinedTruck)
            ->visible(fn () => self::available())
            ->requiresConfirmation()->modalHeading('Buat resi untuk lead terpilih?')
            ->modalDescription('Hanya lead di tahap Dikemas & dikirim tanpa kiriman aktif. Kurir: pilihan pembeli, atau kurir bawaan. Lead yang datanya belum lengkap dilewati dan disebutkan.')
            ->action(function (Collection $records) {
                $stage = (new RetailStages)->stage(RetailStages::SHIPPING);
                $booked = 0;
                $skipped = [];

                foreach ($records as $lead) {
                    if ($stage === null || (int) $lead->stage_id !== (int) $stage->getKey() || Shipment::latestFor($lead) !== null) {
                        $skipped[] = $lead->name.' (bukan tahap Dikemas & dikirim atau sudah ada kiriman)';

                        continue;
                    }

                    $draft = app(Shipments::class)->draft($lead);

                    if (! $draft->ok()) {
                        $skipped[] = $lead->name.': '.($draft->problems[0] ?? 'data belum lengkap');

                        continue;
                    }

                    try {
                        app(Shipments::class)->book($lead, $draft, auth()->user());
                        $booked++;
                    } catch (CarrierException $e) {
                        $skipped[] = $lead->name.': '.$e->getMessage();
                    }
                }

                Notification::make()->title("{$booked} kiriman dibuat")->body($skipped !== [] ? 'Dilewati: '.implode('; ', array_slice($skipped, 0, 10)) : null)
                    ->{$skipped === [] ? 'success' : 'warning'}()->persistent()->send();
            })->deselectRecordsAfterCompletion();
    }

    /** Bulk "Cetak label": the labels of the selected leads merged into one PDF (App\Shipping\Shipments\LabelSheet), opened in a new tab. */
    public static function bulkLabels(): BulkAction
    {
        return BulkAction::make('bulkShipmentLabels')->label('Cetak label')->icon(Heroicon::OutlinedPrinter)
            ->visible(fn () => self::available())
            ->action(function (Collection $records, $livewire) {
                $sheet = app(LabelSheet::class)->make($records);
                $merged = count($sheet['merged']);
                $skipped = count($sheet['skipped']);
                $lines = array_map(fn ($s) => '• '.e($s['name']).': '.e($s['reason']), array_slice($sheet['skipped'], 0, 20));

                if ($skipped > 20) {
                    $lines[] = '… dan '.($skipped - 20).' lainnya';
                }

                if ($sheet['url'] !== null) {
                    array_unshift($lines, '<a href="'.e($sheet['url']).'" target="_blank" style="text-decoration:underline">Buka PDF label</a> (berlaku 1 jam)');
                    $livewire->js('window.open('.json_encode($sheet['url']).', "_blank")');
                }

                Notification::make()->title("{$merged} label digabung · {$skipped} dilewati")
                    ->body($lines === [] ? null : new HtmlString(implode('<br>', $lines)))
                    ->{$merged === 0 ? 'warning' : ($skipped === 0 ? 'success' : 'warning')}()->persistent()->send();
            });
    }
}
