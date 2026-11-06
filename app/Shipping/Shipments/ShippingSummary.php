<?php

namespace App\Shipping\Shipments;

use App\Enums\Role;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Models\CarrierAccount;
use App\Models\Lead;
use App\Models\Shipment;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The numbers of the Pengiriman menu for a period (the daily report's "Pengiriman" block and the admins' morning
 * notice): automatic resi made and their COD, failed ones, leads left to complete, and what is still unprinted
 * or needs doing now. Never a buyer's name or number.
 */
class ShippingSummary
{
    /** The local hour the morning notice goes out. */
    public const HOUR = 7;

    /**
     * @param  Builder|null  $leads  the leads the reader may see (an agent: their own), null for all
     * @return array{auto: int, auto_cod: int, failed: int, skipped: int, unprinted: int, action: int, balance: int}
     */
    public function numbers(CarbonImmutable $from, CarbonImmutable $to, ?Builder $leads = null): array
    {
        $own = fn (Builder $q) => $leads === null ? $q : $q->whereIn('lead_id', (clone $leads)->select('leads.id'));
        $auto = $own(Shipment::query()->where('automatic', true)->whereBetween('created_at', [$from, $to]));
        $failedLeads = ($leads ? clone $leads : Lead::query())->where('auto_shipment_state', AutoShipments::FAILED)->whereBetween('leads.updated_at', [$from, $to])->count();

        return [
            'auto' => (clone $auto)->count(),
            'auto_cod' => (int) (clone $auto)->where('is_cod', true)->sum('cod_amount'),
            'failed' => $failedLeads + $own(Shipment::query()->where('state', Shipment::FAILED)->where('automatic', false)->whereBetween('updated_at', [$from, $to]))->count(),
            'skipped' => ($leads ? clone $leads : Lead::query())->where('auto_shipment_state', AutoShipments::SKIPPED)->whereBetween('leads.updated_at', [$from, $to])->count(),
            'unprinted' => $own(Shipment::query()->tab('unprinted'))->count(),
            'action' => $own(Shipment::query()->tab('action'))->whereNull('final_at')->count(),
            'balance' => $own(Shipment::query()->tab('balance'))->count(),
        ];
    }

    /** The admins' and team leaders' notice about yesterday, once a day from 07:00 in the agency's time zone, when there is anything to say. */
    public function sendIfDue(Tenant $tenant): bool
    {
        $tz = $tenant->timezone ?: 'Asia/Jakarta';
        $now = CarbonImmutable::now($tz);

        if ($now->hour < self::HOUR || ! Cache::add('shipping-summary:'.$tenant->getKey().':'.$now->toDateString(), 1, now()->addDays(2))) {
            return false;
        }

        $n = $this->numbers($now->subDay()->startOfDay()->utc(), $now->startOfDay()->utc());

        if (array_sum($n) === 0) {
            return false;
        }

        $people = $tenant->activeUsers()->wherePivotIn('role', [Role::Admin->value, Role::Manager->value])->get();
        $rp = fn (int $v) => 'Rp'.number_format($v, 0, ',', '.');
        $lines = array_filter([
            $n['auto'] > 0 ? "{$n['auto']} resi otomatis kemarin".($n['auto_cod'] > 0 ? ', total COD '.$rp($n['auto_cod']) : '') : null,
            $n['failed'] > 0 ? "{$n['failed']} resi gagal dibuat" : null,
            $n['skipped'] > 0 ? "{$n['skipped']} lead perlu dilengkapi untuk resi" : null,
            $n['unprinted'] > 0 ? "{$n['unprinted']} resi belum dicetak" : null,
            $n['action'] > 0 ? "{$n['action']} kiriman perlu tindakan" : null,
            $n['balance'] > 0 ? "{$n['balance']} kiriman menunggu saldo" : null,
            Shipments::lowBalance($account = CarrierAccount::query()->first()) ? 'saldo '.config('carriers.labels.'.$account->provider, 'penyedia').' rendah ('.$rp((int) $account->balance).')' : null,
        ]);

        Notification::make()->title('Ringkasan pengiriman')->body(implode(' · ', $lines))->info()
            ->actions([Action::make('open')->label('Buka Pengiriman')->url(ShipmentResource::getUrl('index', panel: 'app', tenant: $tenant))])
            ->sendToDatabase($people);

        return true;
    }
}
