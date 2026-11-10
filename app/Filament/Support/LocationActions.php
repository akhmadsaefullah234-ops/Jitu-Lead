<?php

namespace App\Filament\Support;

use App\Models\Lead;
use App\Shipping\Carriers\CarrierException;
use App\Shipping\Carriers\CarrierLocation;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Shipments\ProviderLocations;
use App\Support\CurrentTenant;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

/**
 * "Pilih lokasi Everpro" (lead, Inbox, Pengiriman): the buyer's place at the shipping provider when matching found
 * several or none. The choice is kept for the lead and for every later address in the same kelurahan.
 */
final class LocationActions
{
    public static function choose(Closure $lead, string $name = 'chooseLocation'): Action
    {
        $provider = fn () => Carriers::label(Carriers::account(app(CurrentTenant::class)->get())?->provider ?? 'everpro');

        return Action::make($name)->label(fn () => 'Pilih lokasi '.$provider())->icon(Heroicon::OutlinedMapPin)
            ->color(fn ($record = null) => $lead($record)?->provider_location_state === ProviderLocations::EXACT ? 'gray' : 'warning')
            ->visible(fn ($record = null) => ShipmentActions::available() && ($l = $lead($record)) !== null && ! $l->trashed() && ProviderLocations::key($l) !== null)
            ->badge(fn ($record = null) => ProviderLocations::LABELS[$lead($record)?->provider_location_state] ?? null)
            ->badgeColor(fn ($record = null) => $lead($record)?->provider_location_state === ProviderLocations::EXACT ? 'success' : 'warning')
            ->modalHeading(fn () => 'Lokasi '.$provider().' untuk alamat pembeli')
            ->modalDescription(fn ($record = null) => self::describe($lead($record)))
            ->fillForm(fn ($record = null) => ['location' => $lead($record)?->provider_location_id])
            ->schema([
                Select::make('location')->label('Lokasi tujuan')->required()->searchable()
                    ->options(fn ($record = null) => self::remember(ProviderLocations::candidates($lead($record))))
                    ->getSearchResultsUsing(fn (string $search) => self::search($search))
                    ->getOptionLabelUsing(fn ($value, $record = null) => self::known((string) $value)?->label() ?? $lead($record)?->provider_location_label)
                    ->helperText('Cari kelurahan, kecamatan atau kode pos. Pilihan ini dipakai lagi untuk alamat di kelurahan yang sama.'),
            ])
            ->modalSubmitActionLabel('Simpan lokasi')
            ->action(function (array $data, $record = null) use ($lead) {
                $place = self::known((string) $data['location']) ?? collect(ProviderLocations::candidates($lead($record)))->first(fn (CarrierLocation $l) => $l->id === (string) $data['location']);

                if ($place === null) {
                    Notification::make()->warning()->title('Lokasi tidak dikenal, cari lagi.')->send();

                    return;
                }

                app(ProviderLocations::class)->choose($lead($record), $place, auth()->user());
                Notification::make()->success()->title('Lokasi disimpan: '.$place->label())->send();
            });
    }

    private static function describe(?Lead $lead): HtmlString
    {
        if ($lead === null) {
            return new HtmlString('');
        }

        $parts = (array) $lead->shipping_address_parts;
        $ours = implode(', ', array_filter([$parts['kelurahan'] ?? null, $parts['kecamatan'] ?? null, $parts['kota'] ?? null, $parts['kode_pos'] ?? null]));

        return new HtmlString('Alamat kita: <strong>'.e($ours ?: '-').'</strong><br>Status: '.e(ProviderLocations::LABELS[$lead->provider_location_state] ?? 'belum dicocokkan')
            .($lead->provider_location_label ? ' · '.e($lead->provider_location_label) : '').($lead->provider_location_note ? '<br><span style="color:var(--gray-500)">'.e($lead->provider_location_note).'</span>' : ''));
    }

    /** @return array<string, string> */
    private static function search(string $search): array
    {
        $search = trim($search);

        if (mb_strlen($search) < 3) {
            return [];
        }

        try {
            $provider = Carriers::for(app(CurrentTenant::class)->get());
            $found = $provider ? (preg_match('/^\d{5}$/', $search) ? $provider->locations($search) : $provider->locations(null, $search)) : [];
        } catch (CarrierException) {
            return [];
        }

        return self::remember(array_slice($found, 0, 30));
    }

    /** @param  list<CarrierLocation>  $places */
    private static function remember(array $places): array
    {
        $out = [];

        foreach ($places as $place) {
            Cache::put(self::cacheKey($place->id), $place->toArray(), 3600);
            $out[$place->id] = $place->label();
        }

        return $out;
    }

    private static function known(string $id): ?CarrierLocation
    {
        $row = Cache::get(self::cacheKey($id));

        return is_array($row) ? CarrierLocation::fromArray($row) : null;
    }

    private static function cacheKey(string $id): string
    {
        return 'provider-location-pick:'.app(CurrentTenant::class)->id().':'.sha1($id);
    }
}
