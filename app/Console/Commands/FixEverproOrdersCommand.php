<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use App\Models\Tenant;
use App\Shipping\Carriers\CarrierException;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Carriers\ListsOrders;
use App\Shipping\Shipments\Shipments;
use App\Support\CurrentTenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Repairs what Everpro's own order numbers (TRD…) broke: parcels booked here that never got their tracking number
 * (looked up in Everpro's order list, then the usual resi flow, one message to the buyer), and imported numbers that
 * are an order number and not a tracking number (swapped silently). A dry run unless --apply.
 */
#[Signature('shipping:fix-everpro-orders {--tenant= : Hanya agensi ini (id)} {--apply : Simpan perubahan; tanpa ini hanya menampilkan}')]
#[Description('Cocokkan kiriman tanpa resi dengan daftar order Everpro dan ganti resi impor TRD… dengan AWB asli')]
class FixEverproOrdersCommand extends Command
{
    public function handle(CurrentTenant $current): int
    {
        $apply = (bool) $this->option('apply');
        $tenants = Shipment::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereIn('state', [Shipment::ORDERED, Shipment::FAILED])->whereNull('awb')->where('source', '!=', Shipment::MANUAL))
                ->orWhere(fn ($q) => $q->where('source', Shipment::MANUAL)->whereRaw('upper(awb) like ?', ['TRD%'])))
            ->when($this->option('tenant'), fn ($q, $id) => $q->where('tenant_id', (int) $id))
            ->distinct()->pluck('tenant_id');
        $totals = ['matched' => 0, 'swapped' => 0, 'skipped' => 0];

        foreach (Tenant::query()->whereKey($tenants)->get() as $tenant) {
            if (! Carriers::for($tenant) instanceof ListsOrders) {
                continue;
            }

            $current->run($tenant, function () use ($tenant, $apply, &$totals) {
                $shipments = app(Shipments::class);
                $provider = Carriers::for($tenant);

                // Booked here, no tracking number: found in the order list, then the usual flow (message once, label).
                $waiting = Shipment::query()->whereIn('state', [Shipment::ORDERED, Shipment::FAILED])->whereNull('awb')->where('source', '!=', Shipment::MANUAL)
                    ->where(fn ($q) => $q->where('state', Shipment::ORDERED)->orWhereNotNull('error'))->orderBy('id')->get();

                foreach ($waiting as $shipment) {
                    $remote = $shipments->remoteOrder($provider, $shipment);

                    if ($remote === null) {
                        $this->line("#{$shipment->id}: tidak ditemukan di daftar order Everpro");
                        $totals['skipped']++;

                        continue;
                    }

                    $this->line("#{$shipment->id}: {$remote->orderNo} → ".($remote->awb ?? 'resi belum terbit'));
                    $totals['matched']++;

                    if (! $apply) {
                        continue;
                    }

                    try {
                        $shipment->forceFill(['state' => Shipment::ORDERED, 'error' => null])->save();
                        $shipments->keepRemote($shipment, $remote);
                        $shipments->place($shipment->refresh());
                    } catch (CarrierException $e) {
                        $shipment->forceFill(['error' => $e->getMessage()])->save();
                        $this->warn("#{$shipment->id}: ".$e->getMessage());
                    }
                }

                // Imported "resi" that is Everpro's order number.
                foreach (Shipment::query()->where('source', Shipment::MANUAL)->whereRaw('upper(awb) like ?', ['TRD%'])->orderBy('id')->get() as $shipment) {
                    if (! Shipments::isProviderOrderNo($shipment->awb)) {
                        continue;
                    }

                    $done = $shipments->fixProviderOrderNo($shipment, $apply);
                    $this->line("#{$shipment->id}: {$done['order']} → ".($done['ok'] ? $done['awb'] : 'dilewati, '.$done['reason']));
                    $totals[$done['ok'] ? 'swapped' : 'skipped']++;
                }
            });
        }

        // Ids and counts only.
        Log::info('Everpro order numbers fixed', $totals + ['apply' => $apply]);
        $this->info(($apply ? 'Diperbaiki' : 'Uji coba (tambahkan --apply untuk menyimpan)').": {$totals['matched']} kiriman dicocokkan, {$totals['swapped']} resi TRD diganti, {$totals['skipped']} dilewati.");

        return self::SUCCESS;
    }
}
