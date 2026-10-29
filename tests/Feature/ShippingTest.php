<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Ai\ShopTools;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\ShippingSettingsPage;
use App\Models\AiDraft;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Shipping\Shipping;
use App\Shipping\ShippingItem;
use App\Shipping\ShippingQuote;
use App\Shipping\ShippingSettings;
use App\Shipping\ZoneShippingRates;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** A shop prices parcels from its own zone table; the AI and the inbox both use it and never guess. */
class ShippingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private Property $kaos;

    private Property $jaket;

    /** @var list<array> Messages API responses, served in order */
    private array $ai = [];

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');

        [$this->kaos, $this->jaket] = $this->inShop(fn () => [
            Property::create(['name' => 'Kaos Lari', 'price_from' => 89000, 'weight_grams' => 300]),
            // 900 g, but 40 x 30 x 10 cm = 2 kg of volume weight
            Property::create(['name' => 'Jaket Windbreaker', 'price_from' => 250000, 'weight_grams' => 900, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 10]),
        ]);

        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => fn () => Http::response(array_shift($this->ai) ?? $this->text('Baik, Kak.')),
            'gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n]),
        ]);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function inShop(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    private function quote(string $city, array $items, ?string $province = null): ShippingQuote
    {
        return $this->inShop(fn () => Shipping::quote($this->shop->fresh(), $city, $province, $items));
    }

    private function prices(ShippingQuote $quote): array
    {
        return collect($quote->options)->mapWithKeys(fn ($o) => [$o->courier => $o->price])->all();
    }

    private function text(string $text): array
    {
        return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function toolUse(string $name, array $input, string $id = 'tu_1'): array
    {
        return ['stop_reason' => 'tool_use', 'content' => [
            ['type' => 'text', 'text' => 'Saya cek dulu ya, Kak.'],
            ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input],
        ]];
    }

    private function channel(AiMode $mode): WaChannel
    {
        return $this->inShop(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => $mode,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function inbound(WaChannel $channel, string $text): WaMessage
    {
        return $this->inShop(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6281234567890'), 'text', $text, null, CarbonImmutable::now(), 'Sari',
        )));
    }

    private function draftFor(WaMessage $m): ?AiDraft
    {
        return $this->inShop(fn () => AiDraft::where('message_id', $m->getKey())->first());
    }

    private function sent(): array
    {
        return $this->inShop(fn () => WaMessage::where('direction', 'out')->pluck('body')->all());
    }

    // The zone table

    public function test_quicker_sport_starts_with_its_zone_table_and_other_shops_do_not(): void
    {
        $settings = ShippingSettings::of($this->shop);
        $this->assertTrue($settings->enabled());
        $this->assertSame('Kedungwaringin, Kab. Bekasi, Jawa Barat', $settings->origin());
        $this->assertSame(['JNE REG', 'SiCepat REG'], $settings->couriers());
        $this->assertCount(9, $settings->zones());

        $other = app(ProvisionTenant::class)('Toko Lain', 'toko-lain', User::factory()->create(), 'retail');
        $this->assertFalse(Shipping::available($other));
    }

    public function test_weight_is_rounded_up_per_kilogram(): void
    {
        // 3 x 300 g = 900 g -> 1 kg; 4 x 300 g = 1.2 kg -> 2 kg
        $one = $this->quote('Bandung', [['product_id' => $this->kaos->id, 'jumlah' => 3]]);
        $this->assertSame([1, 900], [$one->billableKg, $one->actualGrams]);
        $this->assertSame(['JNE REG' => 14000, 'SiCepat REG' => 13000], $this->prices($one));

        $two = $this->quote('Bandung', [['product_id' => $this->kaos->id, 'jumlah' => 4]]);
        $this->assertSame(2, $two->billableKg);
        $this->assertSame(['JNE REG' => 28000, 'SiCepat REG' => 26000], $this->prices($two));
        $this->assertSame('Jawa Barat lain & Banten', $two->zone);
        $this->assertSame('1–3 hari', $two->options[0]->eta());
    }

    public function test_volume_weight_counts_when_it_is_heavier(): void
    {
        $quote = $this->quote('Surabaya', [['product_id' => $this->jaket->id, 'jumlah' => 1]]);

        $this->assertSame([900, 2000, 2], [$quote->actualGrams, $quote->volumeGrams, $quote->billableKg]);
        $this->assertSame(['JNE REG' => 46000, 'SiCepat REG' => 42000], $this->prices($quote));

        // The divisor and the rounding work on whole numbers: 30 x 20 x 10 / 6000 is exactly 1 kg
        $rates = new ZoneShippingRates(new ShippingSettings(['volumetric_divisor' => 6000, 'default_weight_grams' => 1000]));
        $box = new Property(['weight_grams' => 100, 'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 10]);
        $this->assertSame([100, 1000, 1], $rates->weight([new ShippingItem($box, 'Kotak', null, 1)]));
        $this->assertSame([200, 2000, 2], $rates->weight([new ShippingItem($box, 'Kotak', null, 2)]));
        $this->assertSame([1000, 0, 1], $rates->weight([new ShippingItem(null, 'Tanpa berat', null, 1)]), 'No product weight: the default weight');
    }

    public function test_several_items_are_weighed_together_and_totalled(): void
    {
        // 2 kaos (600 g) + 1 jaket (900 g, 2 kg volume): 1.5 kg actual, 2 kg volume -> 2 kg
        $quote = $this->quote('Semarang', [
            ['product_id' => $this->kaos->id, 'varian' => 'L', 'jumlah' => 2],
            ['produk' => 'jaket windbreaker', 'varian' => 'Hitam', 'jumlah' => 1],
        ]);

        $this->assertSame([1500, 2000, 2], [$quote->actualGrams, $quote->volumeGrams, $quote->billableKg]);
        $this->assertSame(['JNE REG' => 42000, 'SiCepat REG' => 38000], $this->prices($quote));
        $this->assertSame(428000, $quote->subtotal());
        $this->assertSame(470000, $quote->toArray()['opsi'][0]['total']);
        $this->assertSame('Jaket Windbreaker', $quote->items[1]->name, 'Found by name');
    }

    public function test_cities_pick_their_zone_before_their_province(): void
    {
        $item = [['product_id' => $this->kaos->id, 'jumlah' => 1]];

        foreach (['Kab. Bekasi' => null, 'Kota Bogor' => 'Jawa Barat', 'Tangerang Selatan' => 'Banten', 'Jakarta Selatan' => null, 'Cikarang' => 'Jabar'] as $city => $province) {
            $this->assertSame('Jabodetabek', $this->quote($city, $item, $province)->zone, $city);
        }

        $this->assertSame('Jawa Barat lain & Banten', $this->quote('Serang', $item)->zone);
        $this->assertSame('Jawa Tengah & DIY', $this->quote('Sleman', $item, 'DIY')->zone);
        $this->assertSame('Sulawesi', $this->quote('Makassar', $item)->zone);
        $this->assertSame(['JNE REG' => 62000, 'SiCepat REG' => 80000], $this->prices($this->quote('Makassar', $item)));
    }

    public function test_far_zones_need_an_admin_and_have_no_price(): void
    {
        $item = [['product_id' => $this->kaos->id, 'jumlah' => 1]];

        foreach ([['Kupang', null], ['Jayapura', null], ['Mataram', 'NTB'], ['Ambon', 'Maluku']] as [$city, $province]) {
            $quote = $this->quote($city, $item, $province);
            $this->assertSame(ShippingQuote::NEEDS_ADMIN, $quote->status, $city);
            $this->assertSame([], $quote->options);
            $this->assertArrayNotHasKey('opsi', $quote->toArray());
        }
    }

    public function test_an_unclear_destination_asks_for_the_city(): void
    {
        $item = [['product_id' => $this->kaos->id, 'jumlah' => 1]];

        $this->assertSame(ShippingQuote::UNCLEAR_DESTINATION, $this->quote('', $item, 'Jawa Barat')->status);
        $this->assertSame(ShippingQuote::UNCLEAR_DESTINATION, $this->quote('Kedungwaringin', $item)->status, 'Unknown city and no province');
        $this->assertTrue($this->quote('Kedungwaringin', $item, 'Jawa Barat')->ok());
    }

    // The AI

    public function test_the_ai_uses_cek_ongkir_and_quotes_only_its_numbers(): void
    {
        $channel = $this->channel(AiMode::Auto);
        $this->ai = [
            $this->toolUse('cek_ongkir', ['items' => [['product_id' => $this->kaos->id, 'varian' => 'L', 'jumlah' => 4]], 'kota' => 'Bandung']),
            $this->text('Ongkir ke Bandung untuk 4 kaos: JNE REG Rp 28.000, SiCepat REG Rp 26.000 (1–3 hari). Total dengan SiCepat Rp 382.000, Kak.'),
        ];

        $message = $this->inbound($channel, 'Kak, ongkir 4 kaos lari ke Bandung berapa?');

        $this->assertSame(AiDraft::SENT, $this->draftFor($message)->status);
        $this->assertStringContainsString('Rp 28.000', $this->sent()[0]);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic')
            && collect($r['tools'] ?? [])->pluck('name')->all() === ['cek_ongkir', 'catat_pesanan']
            && str_contains($r['system'], 'WAJIB memanggil tool cek_ongkir')
            && str_contains($r['system'], $this->kaos->id.' | Kaos Lari | Rp 89.000 | 300 g'));

        // The tool result went back to the model as a tool_result answering the tool call
        Http::assertSent(function (Request $r) {
            $last = collect($r['messages'] ?? [])->last();

            return is_array($last['content'] ?? null) && ($last['content'][0]['type'] ?? null) === 'tool_result'
                && $last['content'][0]['tool_use_id'] === 'tu_1' && str_contains($last['content'][0]['content'], '"ongkir":28000');
        });
    }

    public function test_the_ai_never_quotes_a_shipping_cost_without_the_tool(): void
    {
        $channel = $this->channel(AiMode::Auto);
        $this->ai = [$this->text('Ongkir ke Bandung sekitar Rp 15.000 ya, Kak.')];

        $message = $this->inbound($channel, 'Ongkir ke Bandung berapa?');

        $this->assertSame(AiDraft::HANDOFF, $this->draftFor($message)->status);
        $this->assertStringContainsString('tidak berasal dari cek ongkir', $this->draftFor($message)->reason);
        $this->assertSame([], $this->sent(), 'Nothing reaches the buyer');
    }

    public function test_the_ai_cannot_add_up_a_total_itself(): void
    {
        $channel = $this->channel(AiMode::Auto);
        $this->ai = [
            $this->toolUse('cek_ongkir', ['items' => [['product_id' => $this->kaos->id, 'jumlah' => 1]], 'kota' => 'Bandung']),
            // 14.000 is real, but a total of 105.000 was never returned by the tool (it says 103.000)
            $this->text('Ongkir JNE REG Rp 14.000, jadi total Rp 105.000, Kak.'),
        ];

        $message = $this->inbound($channel, 'Ongkir 1 kaos ke Bandung?');

        $this->assertSame(AiDraft::HANDOFF, $this->draftFor($message)->status);
        $this->assertSame([], $this->sent());
    }

    public function test_a_confirm_admin_zone_goes_to_an_agent(): void
    {
        $channel = $this->channel(AiMode::Auto);
        $this->ai = [
            $this->toolUse('cek_ongkir', ['items' => [['product_id' => $this->kaos->id, 'jumlah' => 1]], 'kota' => 'Kupang']),
            $this->text('Untuk Kupang ongkirnya akan dikonfirmasi admin ya, Kak.'),
        ];

        $message = $this->inbound($channel, 'Kirim ke Kupang bisa?');

        $this->assertSame(AiDraft::HANDOFF, $this->draftFor($message)->status);
        $this->assertSame('Ongkir ke tujuan ini perlu dikonfirmasi admin', $this->draftFor($message)->reason);
        $this->assertSame([], $this->sent());
    }

    public function test_an_unclear_destination_is_asked_about_not_guessed(): void
    {
        $channel = $this->channel(AiMode::Auto);
        $this->ai = [$this->text('Boleh tahu kota atau kecamatan tujuannya, Kak?')];

        $message = $this->inbound($channel, 'Ongkirnya berapa kak?');

        $this->assertSame(AiDraft::SENT, $this->draftFor($message)->status);
        Http::assertSent(fn (Request $r) => str_contains($r['system'] ?? '', 'tanyakan kota atau kecamatannya dulu'));
    }

    public function test_the_ai_records_a_confirmed_order_and_moves_the_lead_to_payment(): void
    {
        $channel = $this->channel(AiMode::Auto);
        $order = [
            'items' => [['product_id' => $this->kaos->id, 'varian' => 'L', 'jumlah' => 2], ['product_id' => $this->jaket->id, 'varian' => 'Hitam', 'jumlah' => 1]],
            'alamat' => 'Jl. Melati No. 3, RT 02/RW 05, Tembalang', 'kota' => 'Semarang', 'provinsi' => 'Jawa Tengah', 'kurir' => 'SiCepat REG',
        ];
        $this->ai = [
            $this->toolUse('catat_pesanan', $order),
            $this->text('Pesanan dicatat, Kak. Total Rp 466.000 dengan SiCepat REG. Silakan transfer ya.'),
        ];

        $message = $this->inbound($channel, 'Oke kak, kirim pakai SiCepat ke Jl. Melati No. 3, RT 02/RW 05, Tembalang, Semarang');

        $this->assertSame(AiDraft::SENT, $this->draftFor($message)->status);
        $lead = $this->inShop(fn () => Lead::with('stage')->first());
        $this->assertSame('Menunggu pembayaran', $lead->stage->name);
        $this->assertSame(38000, $lead->shipping_cost);
        $this->assertSame(466000, (int) $lead->deal_value);
        $this->assertSame('SiCepat REG', $lead->courier);
        $this->assertSame(3, $lead->quantity);
        $this->assertSame($this->kaos->id, $lead->property_id);
        $this->assertStringContainsString('Kaos Lari L', $lead->variant);
        $this->assertStringContainsString('Jl. Melati No. 3', $lead->shipping_address);
        $this->assertStringContainsString('Jawa Tengah', $lead->shipping_address);
        $this->assertCount(2, $lead->order_items);
        $this->assertTrue($this->inShop(fn () => $lead->activities()->where('type', 'order_recorded')->exists()));
    }

    public function test_in_draft_mode_the_order_waits_for_the_agent_to_send(): void
    {
        $channel = $this->channel(AiMode::Draft);
        $this->ai = [
            $this->toolUse('catat_pesanan', ['items' => [['product_id' => $this->kaos->id, 'jumlah' => 1]], 'alamat' => 'Jl. Kenanga 8', 'kota' => 'Bandung', 'kurir' => 'JNE REG']),
            $this->text('Siap Kak, total Rp 103.000 dengan JNE REG. Silakan transfer ya.'),
        ];

        $message = $this->inbound($channel, 'Jadi ambil 1 kaos, kirim JNE ke Jl. Kenanga 8 Bandung');
        $lead = $this->inShop(fn () => Lead::with('stage')->first());
        $this->assertSame('Tanya produk', $lead->stage->name, 'Nothing written while it is a suggestion');
        $this->assertNull($lead->shipping_cost);

        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        Livewire::test(Inbox::class, ['leadId' => $lead->id])
            ->assertSee('Bila dikirim, pesanan ikut dicatat')
            ->call('useAiDraft', $this->draftFor($message)->getKey())
            ->call('send');

        $lead = $this->inShop(fn () => Lead::with('stage')->first());
        $this->assertSame('Menunggu pembayaran', $lead->stage->name);
        $this->assertSame(14000, $lead->shipping_cost);
        $this->assertSame(103000, (int) $lead->deal_value);
    }

    public function test_amounts_of_money_are_read_the_way_buyers_write_them(): void
    {
        $this->assertSame([28000, 11000, 1500000, 45000], ShopTools::amounts('Rp 28.000, 11rb, 1,5 juta, 45k, 2 kg, 1-3 hari'));
        $this->assertFalse(ShopTools::inventsShippingCost('Harga kaos Rp 89.000, Kak.', [], ''), 'Not about shipping');
        $this->assertFalse(ShopTools::inventsShippingCost('Ongkirnya Rp 14.000 seperti tadi.', [], 'assistant: Ongkir JNE REG Rp 14.000'), 'Already in the chat');
        $this->assertTrue(ShopTools::inventsShippingCost('Ongkir 15rb, Kak.', [], 'Kaos Lari | Rp 89.000'));
    }

    public function test_agencies_without_shipping_get_no_tools(): void
    {
        $this->shop->forceFill(['shipping' => null])->save();
        $channel = $this->channel(AiMode::Draft);

        $this->inbound($channel, 'Halo kak');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic') && ! isset($r['tools']));
    }

    // The inbox and the settings

    public function test_the_inbox_button_puts_the_quote_in_the_message_box(): void
    {
        $lead = $this->inShop(fn () => Lead::create(['name' => 'Rudi', 'phone' => '081377770000', 'stage_id' => $this->stage($this->shop, 'Tanya produk')->id, 'property_id' => $this->kaos->id, 'quantity' => 4]));
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(Inbox::class, ['leadId' => $lead->id])
            ->assertActionVisible('shippingQuote')
            ->callAction('shippingQuote', ['items' => [['product_id' => $this->kaos->id, 'varian' => 'L', 'jumlah' => 4]], 'kota' => 'Bandung', 'provinsi' => 'Jawa Barat'])
            ->assertHasNoActionErrors()
            ->assertSet('draft', "Ongkir ke Bandung, Jawa Barat (berat 2 kg):\n- JNE REG: Rp 28.000 (1–3 hari), total Rp 384.000\n- SiCepat REG: Rp 26.000 (1–3 hari), total Rp 382.000");

        Livewire::test(Inbox::class, ['leadId' => $lead->id])
            ->callAction('shippingQuote', ['items' => [['product_id' => $this->kaos->id, 'jumlah' => 1]], 'kota' => 'Kupang'])
            ->assertNotified('Perlu konfirmasi admin')
            ->assertSet('draft', '');
    }

    public function test_an_admin_edits_the_zone_table(): void
    {
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(ShippingSettingsPage::class)
            ->assertSee('Jabodetabek')
            ->set('data.zones', [[
                'name' => 'Seluruh Jawa', 'provinces' => ['Jawa Barat', 'Jawa Tengah'], 'cities' => [], 'excluded_cities' => ['Depok'],
                'confirm_admin' => false, 'eta_min' => 2, 'eta_max' => 3, 'rates' => [['courier' => 'JNE REG', 'per_kg' => 15000]],
            ]])
            ->call('save')
            ->assertHasNoErrors();

        $quote = $this->quote('Bandung', [['product_id' => $this->kaos->id, 'jumlah' => 1]]);
        $this->assertSame(['JNE REG' => 15000], $this->prices($quote));
        $this->assertSame(ShippingQuote::NEEDS_ADMIN, $this->quote('Depok', [['product_id' => $this->kaos->id, 'jumlah' => 1]])->status, 'Excluded and in no other zone');
    }

    public function test_only_a_shop_admin_sees_the_shipping_settings(): void
    {
        $property = $this->makeTenant('griya-baru');

        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        $this->get('/app/quicker-sport/pengiriman')->assertOk()->assertSee('Asal dan kurir');

        $this->actingInTenant($this->adminOf($property), $property);
        $this->get('/app/griya-baru/pengiriman')->assertForbidden();
    }
}
