<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Automation\RetailStages;
use App\Filament\Pages\Pipeline;
use App\Models\CarrierAccount;
use App\Models\Lead;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Models\User;
use App\Shipping\Carriers\ShipmentStatus;
use App\Shipping\Shipments\AutoShipments;
use App\Shipping\Shipments\PackingState;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pipeline "Dikemas" and "Dikirim": the shop's "Dikemas & dikirim" stage shown as two columns read from each lead's
 * parcel, without changing the lead's stage. Dragging between them marks the label printed (or not).
 */
class PackingPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->inShop(fn () => CarrierAccount::query()->create(['provider' => 'everpro', 'sandbox' => true, 'credentials' => ['client_key' => 'k', 'client_secret' => 's'], 'settings' => []]));
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
    }

    private function inShop(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    private function packing(): int
    {
        return (int) $this->inShop(fn () => (new RetailStages)->stage(RetailStages::SHIPPING)->getKey());
    }

    private function lead(string $name, array $extra = []): Lead
    {
        return $this->inShop(fn () => Lead::create($extra + [
            'name' => $name, 'phone' => '0812'.random_int(10000000, 99999999), 'stage_id' => $this->packing(), 'owner_id' => $this->adminOf($this->shop)->getKey(),
            'payment_type' => 'cod', 'courier' => 'JNE REG', 'deal_value' => 100000,
        ]));
    }

    private function parcel(Lead $lead, array $attributes = []): Shipment
    {
        return $this->inShop(fn () => Shipment::query()->create($attributes + [
            'lead_id' => $lead->getKey(), 'provider' => 'everpro', 'client_order_no' => 'JL-T-'.++$this->n, 'state' => Shipment::SHIPPED,
            'awb' => '63297326001778'.str_pad((string) $this->n, 2, '0', STR_PAD_LEFT), 'courier' => 'JNE REG', 'status' => ShipmentStatus::AWB_CREATED, 'is_cod' => true,
            'label_path' => 'labels/x.pdf',
        ]));
    }

    public function test_the_shipping_stage_shows_as_dikemas_and_dikirim_from_each_parcel(): void
    {
        $none = $this->lead('Tanpa Resi', ['auto_shipment_state' => AutoShipments::SKIPPED, 'auto_shipment_note' => 'Resi otomatis tidak dibuat: alamat belum ada RT/RW atau dusun. Buat resi manual.']);
        $ready = $this->lead('Siap Cetak');
        $this->parcel($ready);
        $printed = $this->lead('Sudah Cetak');
        $this->parcel($printed, ['printed_at' => now()->subDays(2)]);
        $failed = $this->lead('Gagal Antar');
        $this->parcel($failed, ['printed_at' => now()->subDays(4), 'status' => ShipmentStatus::DELIVERY_FAILED]);
        $balance = $this->lead('Saldo');
        $this->parcel($balance, ['state' => Shipment::PENDING, 'awb' => null, 'balance_wait_at' => now()]);

        $page = Livewire::test(Pipeline::class);
        $columns = collect($page->viewData('columns'))->keyBy('key');
        $stage = $this->packing();

        $this->assertSame('Dikemas', $columns[$stage.'-packed']['name']);
        $this->assertSame(['Saldo', 'Siap Cetak', 'Tanpa Resi'], $columns[$stage.'-packed']['leads']->pluck('name')->sort()->values()->all());
        $this->assertSame(['Gagal Antar', 'Sudah Cetak'], $columns[$stage.'-sent']['leads']->pluck('name')->sort()->values()->all());
        $this->assertSame(3, $columns[$stage.'-packed']['count']);
        $this->assertSame(300000, $columns[$stage.'-packed']['value']);
        $this->assertSame(200000, $columns[$stage.'-sent']['value']);
        $this->assertFalse($columns->has((string) $stage), 'No single column for the stage any more.');

        $page->assertSee('Perlu resi')->assertSee('alamat belum ada RT/RW atau dusun')->assertSee('saldo Everpro kurang')
            ->assertSee('Siap cetak')->assertSee('Menunggu jemput')->assertSee('2 hari sejak dicetak')->assertSee('Gagal antar')->assertSee('COD');
        $this->assertSame((int) $stage, (int) $this->inShop(fn () => $none->fresh()->stage_id), 'The lead keeps its stage.');
    }

    public function test_dragging_between_dikemas_and_dikirim_marks_the_label_printed_and_back(): void
    {
        $lead = $this->lead('Siap Cetak');
        $parcel = $this->parcel($lead);
        $stage = $this->packing();

        Livewire::test(Pipeline::class)->call('moveLead', $lead->id, $stage, 'sent');
        $this->assertNotNull($this->inShop(fn () => $parcel->fresh()->printed_at));
        $this->assertSame($this->adminOf($this->shop)->id, $this->inShop(fn () => $parcel->fresh()->printed_by));

        Livewire::test(Pipeline::class)->call('moveLead', $lead->id, $stage, 'packed');
        $this->assertNull($this->inShop(fn () => $parcel->fresh()->printed_at));
        $this->assertSame($stage, (int) $this->inShop(fn () => $lead->fresh()->stage_id));

        // Without a resi there is nothing to mark.
        $none = $this->lead('Tanpa Resi');
        Livewire::test(Pipeline::class)->call('moveLead', $none->id, $stage, 'sent')->assertNotified('Belum bisa ditandai dicetak');

        // Another stage moves the lead as before.
        $done = $this->inShop(fn () => (new RetailStages)->stage(RetailStages::PAYMENT)->getKey());
        Livewire::test(Pipeline::class)->call('moveLead', $lead->id, $done, null);
        $this->assertSame((int) $done, (int) $this->inShop(fn () => $lead->fresh()->stage_id));
    }

    public function test_the_shipping_filters_and_the_setting(): void
    {
        $this->lead('Tanpa Resi');
        $this->parcel($ready = $this->lead('Siap Cetak'));
        $this->parcel($failed = $this->lead('Gagal Antar'), ['printed_at' => now(), 'status' => ShipmentStatus::DELIVERY_FAILED]);
        $names = fn ($page) => collect($page->viewData('columns'))->flatMap(fn ($c) => $c['leads']->pluck('name'))->sort()->values()->all();

        $this->assertSame(['Tanpa Resi'], $names(Livewire::test(Pipeline::class)->set('filter', 'resi')));
        $this->assertSame(['Siap Cetak'], $names(Livewire::test(Pipeline::class)->set('filter', 'print')));
        $this->assertSame(['Gagal Antar'], $names(Livewire::test(Pipeline::class)->set('filter', 'failed')));

        // Off: one column again.
        Livewire::test(Pipeline::class)->callAction('settings', ['split_shipping' => false]);
        $this->assertFalse(PackingState::splitEnabled($this->shop->fresh()));
        $this->assertTrue(collect(Livewire::test(Pipeline::class)->viewData('columns'))->pluck('key')->contains((string) $this->packing()));

        // A shop without a shipping provider keeps one column by default; property agencies never split.
        $other = app(ProvisionTenant::class)('Toko Lain', 'toko-lain', User::factory()->create(), 'retail');
        $this->assertFalse(PackingState::splitEnabled($other));
        $this->assertFalse(PackingState::splitEnabled($this->makeTenant()));
    }
}
