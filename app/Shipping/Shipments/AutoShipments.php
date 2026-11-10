<?php

namespace App\Shipping\Shipments;

use App\Automation\RetailStages;
use App\Leads\AddressCheck;
use App\Models\CarrierAccount;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Shipment;
use App\Models\User;
use App\Shipping\Carriers\CarrierException;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Resi otomatis" (Pengaturan > Pengiriman, off by default): a lead entering the trigger stage (Dikemas & dikirim)
 * that has everything a parcel needs is booked with the provider after a wait the agent can cancel in. Never
 * guessed: an unknown or unpaid way of paying, an address without RT/RW and without a house number or landmark
 * (App\Leads\AddressCheck), an address without kelurahan, kecamatan, city,
 * province and postal code, a destination the provider does not match with certainty, an unknown product weight,
 * a buyer's courier that is not offered, a lead marked as spam, the day's limit or a COD above the limit leave it
 * to the agent, with the reason on the lead. A provider error is not retried: the lead shows "Resi gagal dibuat"
 * and its owner (else the admins) is told.
 *
 * A shortage of balance is no failure: the parcel waits ("Menunggu saldo", no order made for a prepaid parcel) and is
 * sent again by itself once the balance covers it; the admins are told once.
 *
 * Lead columns: auto_shipment_state (scheduled, cancelled, skipped, failed, balance, done), auto_shipment_at (when it is
 * made), auto_shipment_note (why not, or what was made).
 */
class AutoShipments
{
    public const SCHEDULED = 'scheduled';

    public const CANCELLED = 'cancelled';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public const DONE = 'done';

    /** Booked, but held until the provider's balance covers it (App\Shipping\Shipments\Shipments::waitForBalance). */
    public const WAITING_BALANCE = 'balance';

    public function __construct(private Shipments $shipments, private CurrentTenant $current, private StatusNotices $notices) {}

    /** @return array{enabled: bool, stage_id: ?int, delay: int, daily: int, cod_max: int} */
    public static function settings(?CarrierAccount $account): array
    {
        $stage = $account?->setting('auto.stage_id');

        return [
            'enabled' => (bool) $account?->setting('auto.enabled', false) && $account?->hasCredentials(),
            'stage_id' => $stage !== null ? (int) $stage : (new RetailStages)->stage(RetailStages::SHIPPING)?->getKey(),
            'delay' => max(0, (int) ($account?->setting('auto.delay_minutes') ?? config('carriers.auto.delay_minutes', 30))),
            'daily' => max(0, (int) ($account?->setting('auto.daily_limit') ?? config('carriers.auto.daily_limit', 50))),
            'cod_max' => max(0, (int) ($account?->setting('auto.cod_max') ?? config('carriers.auto.cod_max', 1000000))),
        ];
    }

    public function enabled(): bool
    {
        return self::settings($this->shipments->account())['enabled'];
    }

    /** The lead entered a stage (by the AI or an agent): scheduled when it is the trigger stage and nothing is missing. */
    public function entered(Lead $lead): void
    {
        $settings = self::settings($this->shipments->account());

        if (! $settings['enabled'] || (int) $lead->stage_id !== (int) $settings['stage_id'] || Shipment::latestFor($lead) !== null
            || $lead->auto_shipment_state === self::SCHEDULED) {
            return;
        }

        $problems = $this->problems($lead);

        if ($problems !== []) {
            $this->mark($lead, self::SKIPPED, 'Resi otomatis tidak dibuat: '.implode(', ', $problems).'. Buat resi manual.');

            return;
        }

        $at = now()->addMinutes($settings['delay']);
        $lead->forceFill(['auto_shipment_state' => self::SCHEDULED, 'auto_shipment_at' => $at, 'auto_shipment_note' => null])->save();
        $lead->activities()->create(['type' => 'note', 'body' => 'Resi otomatis dijadwalkan '.$at->timezone($this->timezone())->format('H:i').' ('.$settings['delay'].' menit lagi). Batalkan dari lead atau Inbox bila perlu.']);
    }

    public function cancel(Lead $lead, ?User $by = null): void
    {
        if ($lead->auto_shipment_state !== self::SCHEDULED) {
            return;
        }

        $lead->forceFill(['auto_shipment_state' => self::CANCELLED, 'auto_shipment_note' => 'Dibatalkan'.($by ? ' oleh '.$by->name : '').'.'])->save();
        $lead->activities()->create(['user_id' => $by?->getKey(), 'type' => 'note', 'body' => 'Resi otomatis dibatalkan. Buat resi manual bila perlu.']);
    }

    /** Minutes until a scheduled resi is made (rounded up), else null. */
    public static function minutesLeft(Lead $lead): ?int
    {
        if ($lead->auto_shipment_state !== self::SCHEDULED || $lead->auto_shipment_at === null) {
            return null;
        }

        return max(0, (int) ceil(now()->diffInSeconds($lead->auto_shipment_at, false) / 60));
    }

    /** Makes the resi of every lead whose wait is over (shipping:auto-resi, every minute). */
    public function due(): int
    {
        $made = 0;

        foreach (Lead::query()->where('auto_shipment_state', self::SCHEDULED)->where('auto_shipment_at', '<=', now())->orderBy('auto_shipment_at')->limit(200)->get() as $lead) {
            try {
                $made += $this->make($lead) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
                $this->fail($lead, 'kesalahan sistem');
            }
        }

        return $made;
    }

    /** Checks everything again (things change during the wait), then books. */
    public function make(Lead $lead): bool
    {
        $lead = Lead::query()->whereKey($lead->getKey())->where('auto_shipment_state', self::SCHEDULED)->first();

        if ($lead === null) {
            return false;
        }

        $settings = self::settings($this->shipments->account());

        if (! $settings['enabled']) {
            $this->mark($lead, self::CANCELLED, 'Resi otomatis dimatikan sebelum waktunya.');

            return false;
        }

        if ((int) $lead->stage_id !== (int) $settings['stage_id']) {
            $this->mark($lead, self::CANCELLED, 'Resi otomatis batal: lead sudah pindah tahap.');

            return false;
        }

        if (Shipment::latestFor($lead) !== null) {
            $lead->forceFill(['auto_shipment_state' => self::DONE, 'auto_shipment_note' => 'Sudah ada resi.'])->save();

            return false;
        }

        $problems = $this->problems($lead);

        if ($problems === [] && $settings['daily'] > 0 && $this->madeToday() >= $settings['daily']) {
            $problems[] = 'batas harian '.$settings['daily'].' resi otomatis tercapai';
        }

        if ($problems !== []) {
            $this->mark($lead, self::SKIPPED, 'Resi otomatis tidak dibuat: '.implode(', ', $problems).'. Buat resi manual.');

            return false;
        }

        try {
            $draft = $this->shipments->draft($lead);
        } catch (CarrierException $e) {
            return $this->fail($lead, $e->getMessage());
        }

        // The provider could not be reached or refused the prices (balance, an outage): not tried again, the agent is told.
        if ($draft->problems !== [] && $draft->destination !== null && $draft->rates === []) {
            return $this->fail($lead, implode(' ', $draft->problems));
        }

        $problems = [...$draft->problems, ...$this->draftProblems($lead, $draft, $settings)];

        if ($problems !== []) {
            $this->mark($lead, self::SKIPPED, 'Resi otomatis tidak dibuat: '.implode(' ', array_map(fn ($p) => rtrim($p, '.').'.', $problems)).' Buat resi manual.');

            return false;
        }

        try {
            [$shipment, $new] = $this->shipments->book($lead, $draft, automatic: true);
        } catch (CarrierException $e) {
            return $this->fail($lead, $e->getMessage());
        }

        if (! $new) {
            $lead->forceFill(['auto_shipment_state' => self::DONE, 'auto_shipment_note' => 'Sudah ada resi.'])->save();

            return false;
        }

        $rp = fn (?int $v) => 'Rp'.number_format((int) $v, 0, ',', '.');
        $subsidy = $draft->rate->price - (int) $draft->charged;
        $detail = $draft->rate->name().' · ongkir asli '.$rp($draft->rate->price).' · ditagih '.$rp($draft->charged)
            .($subsidy > 0 ? ' (subsidi '.$rp($subsidy).')' : '').' · '.($draft->cod ? 'COD '.$rp($draft->codAmount) : 'transfer');

        $lead->refresh();

        if (! in_array($lead->auto_shipment_state, [self::FAILED, self::WAITING_BALANCE], true)) {
            $lead->forceFill(['auto_shipment_state' => self::DONE, 'auto_shipment_note' => $detail])->save();
        }

        $lead->activities()->create(['type' => 'shipment_auto', 'body' => 'Resi dibuat otomatis: '.$detail.'.', 'meta' => [
            'shipment' => $shipment->getKey(), 'ongkir' => $draft->rate->price, 'ditagih' => $draft->charged, 'subsidi' => max(0, $subsidy), 'cod' => $draft->cod ? $draft->codAmount : null,
        ]]);

        return true;
    }

    /**
     * What the lead itself lacks, without asking the provider (also shown in the lead list as "Perlu dilengkapi").
     *
     * @return list<string>
     */
    public function problems(Lead $lead): array
    {
        $problems = [];
        $payment = Shipments::payment($lead);
        $parts = (array) $lead->shipping_address_parts;

        if ($payment === null) {
            $problems[] = 'cara bayar '.(blank($lead->payment_type) ? 'kosong' : 'belum dipastikan agen');
        } elseif (Shipments::unpaid($lead, $payment)) {
            $problems[] = 'transfer belum dikonfirmasi lunas';
        }

        // With RT/RW the house number and landmark are optional; without one (dusun, RT/RW 0/000) one of them is needed.
        if (AddressCheck::needsLandmark($parts)) {
            $problems[] = AddressCheck::PROBLEM;
        }

        foreach (['kelurahan' => 'kelurahan', 'kecamatan' => 'kecamatan', 'kota' => 'kota', 'provinsi' => 'provinsi', 'kode_pos' => 'kode pos'] as $key => $label) {
            if (blank($parts[$key] ?? null)) {
                $problems[] = 'alamat belum ada '.$label;
            }
        }

        $items = $this->shipments->items($lead);

        if ($items === []) {
            $problems[] = 'produk dan jumlah belum diisi';
        } else {
            $products = Property::query()->whereIn('id', array_filter(array_column($items, 'product_id')))->get()->keyBy('id');

            foreach ($items as $item) {
                $product = $products[$item['product_id']] ?? null;

                if ($product === null) {
                    $problems[] = 'produk "'.$item['name'].'" tidak ada di katalog';
                } elseif (! $product->weight_grams) {
                    $problems[] = 'berat '.$product->name.' belum diisi di katalog';
                }
            }

            if (blank($lead->variant) && collect((array) $lead->order_items)->every(fn ($i) => blank($i['variant'] ?? null))) {
                $problems[] = 'varian belum diisi';
            }
        }

        if ($lead->spamReasons() !== []) {
            $problems[] = 'dicurigai spam';
        }

        // Resi otomatis runs only for a place matched "pasti" (or not matched yet: then the postal code decides).
        if (ProviderLocations::key($lead) === $lead->provider_location_key && $lead->provider_location_state === ProviderLocations::MULTIPLE) {
            $problems[] = 'kelurahan tujuan perlu dipilih';
        } elseif (ProviderLocations::key($lead) === $lead->provider_location_key && $lead->provider_location_state === ProviderLocations::NONE) {
            $problems[] = 'lokasi tujuan tidak ketemu di '.config('carriers.labels.'.$this->shipments->account()?->provider, 'penyedia');
        }

        return array_values(array_unique($problems));
    }

    /**
     * What only the provider's answer shows: a place it does not match with certainty, the buyer's courier not
     * offered, a COD above the limit.
     *
     * @return list<string>
     */
    private function draftProblems(Lead $lead, ShipmentDraft $draft, array $settings): array
    {
        if ($draft->problems !== [] || $draft->destination === null || $draft->rate === null) {
            return [];
        }

        $problems = [];
        $postal = (string) (((array) $lead->shipping_address_parts)['kode_pos'] ?? '');

        // The place was matched by its kelurahan (and kecamatan) name among the postal code's places; certain only when its own postal code is the buyer's.
        // A place matched "pasti" before (ProviderLocations, or an agent's choice for this kelurahan) is certain.
        $matched = ProviderLocations::place($lead)?->id === $draft->destination->id;

        if (! $matched && $draft->destination->postalCode !== $postal) {
            $problems[] = 'kelurahan tujuan di '.config('carriers.labels.'.$this->shipments->account()?->provider, 'penyedia').' tidak pasti cocok dengan kode pos';
        }

        if (filled($lead->courier) && ! $draft->rate->matches($lead->courier)) {
            $problems[] = 'kurir pilihan pembeli ('.$lead->courier.') tidak tersedia';
        }

        if ($draft->cod && $settings['cod_max'] > 0 && $draft->codAmount > $settings['cod_max']) {
            $problems[] = 'nilai COD Rp'.number_format($draft->codAmount, 0, ',', '.').' di atas batas Rp'.number_format($settings['cod_max'], 0, ',', '.');
        }

        return $problems;
    }

    /** Leads whose own data is complete (the lead list's "Siap resi" filter; the provider checks come later). */
    public static function scopeReady(Builder $query, bool $ready = true): Builder
    {
        $part = fn (string $key) => "coalesce(leads.shipping_address_parts->>'{$key}', '') <> ''";
        $sql = '('.implode(' and ', [
            "((leads.payment_type = 'cod' and leads.payment_type_source is null) or (leads.payment_type in ('transfer', 'other') and leads.payment_type_source is null and (leads.paid_at is not null or leads.payment_proof_at is not null)))",
            "(coalesce(leads.shipping_address_parts->>'rt_rw', '') ~ '[1-9]' or ".$part('house').' or '.$part('landmark').')',
            $part('kelurahan'), $part('kecamatan'), $part('kota'), $part('provinsi'), $part('kode_pos'),
            '(leads.property_id is not null or leads.order_items is not null)',
            "(leads.custom_fields->'spam') is null",
            // The provider's place: "pasti", or not matched yet (ProviderLocations).
            "coalesce(leads.provider_location_state, 'exact') = 'exact'",
        ]).')';

        $active = fn ($q) => $q->whereIn('state', [Shipment::PENDING, Shipment::ORDERED, Shipment::SHIPPED]);

        $query->whereDoesntHave('shipments', $active);

        return $ready
            ? $query->whereRaw("coalesce({$sql}, false)")
            : $query->whereRaw('(leads.property_id is not null or leads.order_items is not null)')
                ->whereHas('stage', fn (Builder $stage) => $stage->where('type', 'open'))
                ->whereRaw("not coalesce({$sql}, false)");
    }

    /** Automatic resi made today (the agency's day). */
    public function madeToday(): int
    {
        $start = CarbonImmutable::now($this->timezone())->startOfDay()->utc();

        return Shipment::query()->where('automatic', true)->where('created_at', '>=', $start)->count();
    }

    private function fail(Lead $lead, string $why): bool
    {
        $this->mark($lead, self::FAILED, 'Resi gagal dibuat otomatis: '.$why);
        $this->notices->alertLead($lead, 'Resi gagal dibuat', $why.' Tidak dicoba ulang otomatis; buat resi manual setelah dicek.');
        Log::warning('Automatic resi failed', ['tenant' => $this->current->id(), 'lead' => $lead->getKey()]);

        return false;
    }

    private function mark(Lead $lead, string $state, string $note): void
    {
        DB::transaction(function () use ($lead, $state, $note) {
            $lead->forceFill(['auto_shipment_state' => $state, 'auto_shipment_note' => mb_substr($note, 0, 500)])->save();
            $lead->activities()->create(['type' => 'note', 'body' => $note]);
        });
    }

    private function timezone(): string
    {
        return $this->current->get()?->timezone ?: 'Asia/Jakarta';
    }
}
