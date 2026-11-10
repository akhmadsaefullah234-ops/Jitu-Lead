<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Automation\RetailStages;
use App\Filament\Pages\Inbox;
use App\Models\CarrierAccount;
use App\Models\Lead;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaConversation;
use App\Shipping\Carriers\ShipmentStatus;
use App\Shipping\Shipments\AutoShipments;
use App\Shipping\Shipments\InboxShipping;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Inbox chips for OK orders: "Order OK · perlu resi" (with why resi otomatis did not run and "Buat resi"),
 * "siap cetak", "Dicetak · menunggu jemput", "Gagal antar", "OK/closing"; the filter "Perlu tindakan pengiriman".
 */
class InboxShippingTest extends TestCase
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

    private function stageId(string $key): int
    {
        return (int) $this->inShop(fn () => (new RetailStages)->stage($key)->getKey());
    }

    /** A lead with a chat; $wroteAt: the buyer's last message. */
    private function chat(string $name, string $stage = RetailStages::SHIPPING, array $extra = [], ?string $wroteAt = '-1 hour', ?string $lastAt = null): Lead
    {
        return $this->inShop(function () use ($name, $stage, $extra, $wroteAt, $lastAt) {
            $lead = Lead::create($extra + [
                'name' => $name, 'phone' => '0812'.str_pad((string) ++$this->n, 8, '0', STR_PAD_LEFT), 'stage_id' => $this->stageId($stage), 'stage_entered_at' => now()->subHours(3),
                'owner_id' => $this->adminOf($this->shop)->getKey(), 'payment_type' => 'cod',
            ]);
            WaConversation::query()->create(['lead_id' => $lead->id, 'phone' => $lead->phone, 'last_inbound_at' => $wroteAt ? now()->modify($wroteAt) : null,
                'last_message_at' => now()->modify($lastAt ?? $wroteAt ?? '-1 hour')]);

            return $lead;
        });
    }

    private function parcel(Lead $lead, array $attributes = []): Shipment
    {
        return $this->inShop(fn () => Shipment::query()->create($attributes + [
            'lead_id' => $lead->getKey(), 'provider' => 'everpro', 'client_order_no' => 'JL-T-'.++$this->n, 'state' => Shipment::SHIPPED,
            'awb' => 'JNE00'.$this->n.'1234', 'courier' => 'JNE REG', 'status' => ShipmentStatus::AWB_CREATED, 'label_path' => 'labels/x.pdf',
        ]));
    }

    public function test_ok_orders_get_a_chip_with_why_the_resi_was_not_made(): void
    {
        $none = $this->chat('Perlu Resi', extra: ['auto_shipment_state' => AutoShipments::SKIPPED, 'auto_shipment_note' => 'Resi otomatis tidak dibuat: cara bayar kosong. Buat resi manual.']);
        $this->parcel($ready = $this->chat('Siap Cetak'));
        $this->parcel($printed = $this->chat('Sudah Cetak'), ['printed_at' => now()]);
        $this->parcel($failed = $this->chat('Gagal Antar'), ['printed_at' => now(), 'status' => ShipmentStatus::DELIVERY_FAILED, 'status_reason' => 'Rumah kosong']);
        $this->parcel($moving = $this->chat('Jalan'), ['printed_at' => now(), 'status' => ShipmentStatus::IN_TRANSIT]);
        $this->parcel($done = $this->chat('Selesai', RetailStages::DONE), ['printed_at' => now(), 'status' => ShipmentStatus::DELIVERED, 'final_at' => now()]);
        $closing = $this->chat('Sudah Konfirmasi', RetailStages::ORDER);
        $this->inShop(fn () => $closing->activities()->create(['type' => 'order_recorded', 'body' => 'Pesanan dicatat']));
        $plain = $this->chat('Tanya Saja', RetailStages::INQUIRY);

        $chips = $this->inShop(fn () => InboxShipping::chips(Lead::query()->get()));

        $this->assertSame('Order OK · perlu resi', $chips[$none->id]['label']);
        $this->assertSame('cara bayar kosong', $chips[$none->id]['reason']);
        $this->assertTrue($chips[$none->id]['create']);
        $this->assertSame('danger', $chips[$none->id]['color']);
        $this->assertSame('Order OK · siap cetak', $chips[$ready->id]['label']);
        $this->assertSame('Dicetak · menunggu jemput', $chips[$printed->id]['label']);
        $this->assertSame(['Gagal antar', 'danger', 'Rumah kosong'], [$chips[$failed->id]['label'], $chips[$failed->id]['color'], $chips[$failed->id]['reason']]);
        $this->assertSame('OK/closing', $chips[$closing->id]['label']);
        $this->assertArrayNotHasKey($moving->id, $chips, 'On its way: no chip.');
        $this->assertArrayNotHasKey($done->id, $chips);
        $this->assertArrayNotHasKey($plain->id, $chips);

        Livewire::test(Inbox::class)->assertSee('Order OK · perlu resi')->assertSee('cara bayar kosong')->assertSee('Buat resi')
            ->assertSee('Gagal antar')->assertSee('OK/closing')->assertSee('Perlu tindakan pengiriman');
        Livewire::test(Inbox::class, ['leadId' => $none->id])->assertSee('Order OK · perlu resi');
    }

    public function test_the_shipping_filter_counts_and_puts_ok_chats_with_new_messages_first(): void
    {
        // Wrote after the OK (stage entered 3 hours ago), but its chat is older than the others.
        $waiting = $this->chat('Menunggu', wroteAt: '-2 hours');
        $this->parcel($ready = $this->chat('Siap Cetak', wroteAt: '-5 hours'));
        $this->chat('Tanya Saja', RetailStages::INQUIRY, wroteAt: '-1 minute');
        $this->chat('Lain', RetailStages::INQUIRY, wroteAt: '-10 minutes');

        $all = Livewire::test(Inbox::class);
        $order = $all->viewData('conversations')->pluck('lead.name')->all();
        $this->assertSame('Menunggu', $order[0], implode(', ', $order));
        $this->assertSame(2, $all->viewData('shippingWaiting'));
        $this->assertSame('Siap Cetak', end($order), 'Wrote before the OK: in its usual place.');

        $filtered = Livewire::test(Inbox::class)->set('filter', 'ship');
        $this->assertEqualsCanonicalizing(['Menunggu', 'Siap Cetak'], $filtered->viewData('conversations')->pluck('lead.name')->all());
    }

    public function test_buat_resi_from_the_chip_opens_the_chat_and_the_form(): void
    {
        $lead = $this->chat('Perlu Resi');

        Livewire::test(Inbox::class)->call('ship', $lead->id)->assertSet('leadId', $lead->id)->assertActionMounted('createShipment');
    }
}
