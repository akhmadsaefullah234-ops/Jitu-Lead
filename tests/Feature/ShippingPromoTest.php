<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Actions\RecordOrder;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Shipping\CourierChoice;
use App\Shipping\Shipments\Shipments;
use App\Shipping\Shipping;
use App\Shipping\ShippingPromos;
use App\Shipping\ShippingQuote;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** A product's shipping promo comes off every quote that holds it: the AI's answer, the recorded order and "Buat resi". */
class ShippingPromoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private Property $tas;

    private Property $kaos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');

        [$this->tas, $this->kaos] = $this->inShop(fn () => [
            Property::create(['name' => 'Tas Badminton', 'price_from' => 199000, 'weight_grams' => 300, 'shipping_promos' => [
                ['type' => 'discount', 'amount' => 30000, 'area' => 'outside_java_bali', 'min_quantity' => 1],
            ]]),
            Property::create(['name' => 'Kaos Lari', 'price_from' => 89000, 'weight_grams' => 300]),
        ]);
    }

    private function inShop(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    private function quote(string $city, array $items, ?string $province = null): ShippingQuote
    {
        return $this->inShop(fn () => Shipping::quote($this->shop->fresh(), $city, $province, $items, courier: CourierChoice::ALL));
    }

    private function prices(ShippingQuote $quote): array
    {
        return collect($quote->options)->mapWithKeys(fn ($o) => [$o->courier => $o->price])->all();
    }

    private function promo(Property $product, array $rules): void
    {
        $this->inShop(fn () => $product->update(['shipping_promos' => $rules]));
    }

    public function test_the_discount_comes_off_outside_java_and_bali_only(): void
    {
        // Makassar, 1 kg: JNE 62.000, SiCepat 80.000
        $quote = $this->quote('Makassar', [['product_id' => $this->tas->id, 'jumlah' => 1]]);
        $this->assertSame(['JNE REG' => 32000, 'SiCepat REG' => 50000], $this->prices($quote));

        $jne = $quote->toArray()['opsi'][0];
        $this->assertSame([62000, 30000, 231000], [$jne['ongkir_normal'], $jne['potongan_ongkir'], $jne['total']]);
        $this->assertSame('Potongan ongkir Rp 30.000 (Tas Badminton, luar Jawa & Bali)', $jne['promo_ongkir']);
        $this->assertStringContainsString('Rp 32.000 (normal Rp 62.000, Potongan ongkir Rp 30.000', $quote->summary());

        // Java: the courier's price, no promo fields
        $bandung = $this->quote('Bandung', [['product_id' => $this->tas->id, 'jumlah' => 1]]);
        $this->assertSame(['JNE REG' => 14000, 'SiCepat REG' => 13000], $this->prices($bandung));
        $this->assertArrayNotHasKey('promo_ongkir', $bandung->toArray()['opsi'][0]);

        // A product without a promo pays the full price
        $this->assertSame(['JNE REG' => 62000, 'SiCepat REG' => 80000], $this->prices($this->quote('Makassar', [['product_id' => $this->kaos->id, 'jumlah' => 1]])));
    }

    public function test_free_cap_minimum_quantity_and_the_single_best_promo(): void
    {
        $item = [['product_id' => $this->kaos->id, 'jumlah' => 1]];

        $this->promo($this->kaos, [['type' => 'free', 'area' => 'provinces', 'provinces' => ['Jawa Barat']]]);
        $this->assertSame(['JNE REG' => 0, 'SiCepat REG' => 0], $this->prices($this->quote('Bandung', $item)));
        $this->assertSame(['JNE REG' => 62000, 'SiCepat REG' => 80000], $this->prices($this->quote('Makassar', $item)));

        $this->promo($this->kaos, [['type' => 'cap', 'amount' => 50000, 'area' => 'all']]);
        $this->assertSame(['JNE REG' => 50000, 'SiCepat REG' => 50000], $this->prices($this->quote('Makassar', $item)));
        $this->assertSame(['JNE REG' => 14000, 'SiCepat REG' => 13000], $this->prices($this->quote('Bandung', $item)), 'under the cap: unchanged');

        $this->promo($this->kaos, [['type' => 'discount', 'amount' => 100000, 'area' => 'all', 'min_quantity' => 2]]);
        $this->assertSame(['JNE REG' => 62000, 'SiCepat REG' => 80000], $this->prices($this->quote('Makassar', $item)), 'below the minimum quantity');
        $this->assertSame(['JNE REG' => 0, 'SiCepat REG' => 0], $this->prices($this->quote('Makassar', [['product_id' => $this->kaos->id, 'jumlah' => 2]])), 'never below zero');

        // Two products with promos: one promo per order, the one that saves most (not added up)
        $this->promo($this->kaos, [['type' => 'discount', 'amount' => 20000, 'area' => 'all']]);
        $both = $this->quote('Makassar', [['product_id' => $this->tas->id, 'jumlah' => 1], ['product_id' => $this->kaos->id, 'jumlah' => 1]]);
        $this->assertSame(30000, $both->options[0]->discount());
        $this->assertStringContainsString('Tas Badminton', $both->options[0]->promo);

        $this->assertSame([], ShippingPromos::rules([['type' => 'discount', 'amount' => 0, 'area' => 'all'], ['type' => 'x', 'area' => 'all'], ['type' => 'free', 'area' => 'provinces']]), 'incomplete rules are ignored');
    }

    public function test_the_recorded_order_charges_the_promo_price(): void
    {
        $lead = $this->inShop(fn () => Lead::create(['name' => 'Rina', 'phone' => '+6281234560437', 'stage_id' => $this->inShop(fn () => Stage::query()->orderBy('position')->value('id'))]));
        $admin = $this->shop->users()->first();

        $result = $this->inShop(fn () => app(RecordOrder::class)($lead, [
            'items' => [['product_id' => $this->tas->id, 'jumlah' => 1]], 'alamat' => 'Jl. Beliang XI B, RT 001/RW 022', 'kota' => 'Makassar',
            'kurir' => 'JNE REG', 'pembayaran' => 'transfer', 'status' => 'konfirmasi',
        ], $admin, 'AI'));

        $this->assertSame('tercatat', $result['status'], json_encode($result));
        $lead = $lead->fresh();
        $this->assertSame(32000, $lead->shipping_cost);
        $this->assertSame(231000, (int) $lead->deal_value);
        $activity = $this->inShop(fn () => $lead->activities()->where('type', 'order_recorded')->first());
        $this->assertStringContainsString('Ongkir JNE REG Rp 32.000 (normal Rp 62.000, Potongan ongkir Rp 30.000', $activity->body);
        $this->assertSame(62000, $activity->meta['ongkir_normal']);
    }

    public function test_the_admin_sets_the_promo_on_the_product_page(): void
    {
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(EditProperty::class, ['record' => $this->kaos->getKey()])
            ->set('activeRelationManager', EditProperty::PROMO)
            ->assertFormFieldVisible('shipping_promos')
            ->fillForm(['shipping_promos' => [['type' => 'discount', 'amount' => 25000, 'area' => 'outside_java_bali', 'min_quantity' => 1, 'label' => '']]])
            ->call('save')->assertHasNoFormErrors();

        $rules = ShippingPromos::rules($this->kaos->fresh()->shipping_promos);
        $this->assertSame([['area' => 'outside_java_bali', 'provinces' => [], 'type' => 'discount', 'amount' => 25000, 'min_quantity' => 1, 'label' => '']], $rules);
        $this->assertSame(['JNE REG' => 37000, 'SiCepat REG' => 55000], $this->prices($this->quote('Makassar', [['product_id' => $this->kaos->id, 'jumlah' => 1]])));
    }

    public function test_a_way_of_paying_the_ai_only_heard_stays_unconfirmed_for_buat_resi(): void
    {
        $stage = $this->inShop(fn () => Stage::query()->orderBy('position')->value('id'));
        $lead = $this->inShop(fn () => Lead::create(['name' => 'Rina', 'phone' => '+6281234560438', 'stage_id' => $stage]));
        $admin = $this->shop->users()->first();
        $order = ['items' => [['product_id' => $this->tas->id, 'jumlah' => 1]], 'alamat' => 'Jl. Beliang XI B, RT 001/RW 022', 'kota' => 'Makassar', 'nama' => 'Rina'];

        // Draft "dicatat COD": the lead says COD, from the chat
        $this->inShop(fn () => app(RecordOrder::class)($lead, $order + ['status' => 'draft', 'pembayaran' => 'cod'], $admin, 'AI'));
        $lead = $lead->fresh();
        $this->assertSame(['cod', 'chat'], [$lead->payment_type, $lead->payment_type_source]);
        $this->assertNull(Shipments::payment($lead));

        // Confirmed without a way of paying: assumed transfer, not confirmed
        $other = $this->inShop(fn () => Lead::create(['name' => 'Budi', 'phone' => '+6281234560439', 'stage_id' => $stage]));
        $this->inShop(fn () => app(RecordOrder::class)($other, $order + ['status' => 'konfirmasi', 'kurir' => 'JNE REG'], $admin, 'AI'));
        $this->assertSame(['transfer', 'chat'], [$other->fresh()->payment_type, $other->fresh()->payment_type_source]);

        // Confirmed with COD: confirmed
        $this->inShop(fn () => app(RecordOrder::class)($lead, $order + ['status' => 'konfirmasi', 'kurir' => 'JNE REG', 'pembayaran' => 'cod'], $admin, 'AI'));
        $this->assertSame(['cod', null], [$lead->fresh()->payment_type, $lead->fresh()->payment_type_source]);

        // An agent editing it confirms it
        $this->inShop(fn () => $other->fresh()->update(['payment_type' => 'cod']));
        $this->assertNull($other->fresh()->payment_type_source);
    }
}
