<?php

namespace App\Console\Commands;

use App\Jobs\MatchProviderLocation;
use App\Models\Lead;
use App\Models\ProviderLocationCorrection;
use App\Models\Tenant;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Shipments\ProviderLocations;
use App\Support\CurrentTenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * How the buyers' places map to the shipping provider's (ProviderLocations), per agency: leads per state
 * (pasti / perlu dipilih / tidak ketemu / belum dicocokkan), the kelurahan that most often do not match, and the
 * agency's corrections. --match queues the leads with an address that were never matched (or changed since).
 */
#[Signature('shipping:locations {--tenant= : Hanya agensi ini (id atau slug)} {--match : Cocokkan lead yang belum dicocokkan (lewat antrean)} {--top=20 : Jumlah kelurahan yang sering tidak cocok}')]
#[Description('Laporan pemetaan alamat ke lokasi penyedia pengiriman (Everpro), dan cocokkan yang belum')]
class ProviderLocationsReportCommand extends Command
{
    public function handle(CurrentTenant $current): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $t) => $q->where(fn ($q) => $q->where('slug', $t)->when(ctype_digit((string) $t), fn ($q) => $q->orWhere('id', (int) $t))))
            ->get()->filter(fn (Tenant $t) => Carriers::configured($t));

        if ($tenants->isEmpty()) {
            $this->line('Tidak ada agensi dengan penyedia pengiriman.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $current->run($tenant, function () use ($tenant) {
                $this->info($tenant->name);
                $withAddress = Lead::query()->whereNotNull('shipping_address_parts')->whereRaw("coalesce(shipping_address_parts->>'kode_pos', shipping_address_parts->>'kelurahan', '') <> ''");
                $counts = (clone $withAddress)->groupBy('provider_location_state')->selectRaw('provider_location_state as s, count(*) as n')->pluck('n', 's');

                $this->table(['Status', 'Lead'], collect([ProviderLocations::EXACT, ProviderLocations::MULTIPLE, ProviderLocations::NONE, ''])
                    ->map(fn ($s) => [$s === '' ? 'belum dicocokkan' : ProviderLocations::LABELS[$s], (int) ($counts[$s] ?? 0)])->all());

                $misses = (clone $withAddress)->whereIn('provider_location_state', [ProviderLocations::MULTIPLE, ProviderLocations::NONE])
                    ->groupBy(DB::raw("shipping_address_parts->>'kode_pos'"), DB::raw("shipping_address_parts->>'kelurahan'"), DB::raw("shipping_address_parts->>'kecamatan'"), DB::raw("shipping_address_parts->>'kota'"), 'provider_location_state')
                    ->selectRaw("shipping_address_parts->>'kode_pos' as kode_pos, shipping_address_parts->>'kelurahan' as kelurahan, shipping_address_parts->>'kecamatan' as kecamatan, shipping_address_parts->>'kota' as kota, provider_location_state as s, count(*) as n")
                    ->orderByDesc('n')->limit((int) $this->option('top'))->get();

                if ($misses->isNotEmpty()) {
                    $this->line('Kelurahan yang sering tidak cocok:');
                    $this->table(['Kode pos', 'Kelurahan', 'Kecamatan', 'Kota', 'Status', 'Lead'],
                        $misses->map(fn ($r) => [$r->kode_pos, $r->kelurahan, $r->kecamatan, $r->kota, ProviderLocations::LABELS[$r->s] ?? $r->s, $r->n])->all());
                }

                $this->line('Koreksi agen tersimpan: '.ProviderLocationCorrection::query()->count().' (dipakai ulang '.(int) ProviderLocationCorrection::query()->sum('used_count').' kali).');

                if ($this->option('match')) {
                    $queued = 0;

                    foreach ((clone $withAddress)->where(fn ($q) => $q->whereNull('provider_location_state')->orWhereNull('provider_location_key'))->open()->cursor() as $lead) {
                        if (ProviderLocations::key($lead) !== $lead->provider_location_key) {
                            MatchProviderLocation::dispatch((int) $tenant->getKey(), (int) $lead->getKey());
                            $queued++;
                        }
                    }

                    $this->line($queued.' lead dikirim ke antrean untuk dicocokkan.');
                }
            });
        }

        return self::SUCCESS;
    }
}
