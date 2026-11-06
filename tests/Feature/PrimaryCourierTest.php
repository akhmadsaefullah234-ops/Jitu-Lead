<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Actions\RecordOrder;
use App\Ai\ShopTools;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\ShippingSettingsPage;
use App\Models\AiDraft;
use App\Models\CarrierAccount;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Shipping\Carriers\CarrierRate;
use App\Shipping\CourierChoice;
use App\Shipping\Shipments\ShipmentDraft;
use App\Shipping\Shipping;
use App\Shipping\ShippingQuote;
use App\Shipping\ShippingSettings;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Buyers are quoted the shop's primary courier (JNE REG) unless they ask for another, and a shop that prices with
 * Everpro quotes Everpro's own rate, falling back to the zone table when it cannot.
 */
class PrimaryCourierTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private Property $kaos;

    /** @var list<array> Messages API responses, served in order */
    private array $ai = [];

    private int $n = 0;

    /** @var list<array{path: string, body: array}> */
    private array $everproCalls = [];

    /** 'ok', 'error' (500) or 'timeout' (no answer in time). */
    private string $everpro = 'ok';

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->kaos = $this->inShop(fn () => Property::create(['name' => 'Kaos Lari', 'price_from' => 89000, 'weight_grams' => 300]));

        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'client-api-sandbox.everpro.id/*' => fn (Request $r) => $this->everproAnswer($r),
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

    private function everproAnswer(Request $r)
    {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $this->everproCalls[] = ['path' => $path, 'body' => $r->data()];

        if ($path === '/auth/v1/token') {
            return Http::response(['data' => ['token' => 'tok', 'expires' => 86400]]);
        }

        if ($this->everpro === 'timeout') {
            throw new ConnectionException('cURL error 28: Operation timed out after 3000 milliseconds');
        }

        if ($this->everpro === 'error') {
            return Http::response(['message' => 'internal error'], 500);
        }

        $rate = fn (string $logistic, string $code, int $price) => ['logistic_name' => $logistic, 'logistic_code' => '', 'rate_code' => $code, 'rate_name' => 'Regular',
            'price' => $price, 'shipment_price_after_discount' => $price, 'surcharge' => 0, 'min_duration' => 2, 'max_duration' => 3, 'signed_key' => 'sig-'.$code];

        return match ($path) {
            // Sumatera Utara: the zone table says Rp 52.000 for JNE, Everpro Rp 47.000
            '/shipment/v2/rates' => Http::response(['data' => ($r->data()['destination_postal_code'] ?? null) === '20111'
                ? ['regular' => [$rate('JNE', 'REG23', 47000), $rate('SiCepat', 'REG', 41000)]]
                : ['regular' => [$rate('JNE', 'REG23', 15000), $rate('SiCepat', 'REG', 13500)]]]),
            '/location/v1/postalcode' => Http::response(['data' => match ($r->data()['postal_code'] ?? '') {
                '44151' => [
                    ['sub_district_id' => 'SD-SUKA', 'sub_district_name' => 'Sukamentri', 'district_name' => 'Garut Kota', 'city_name' => 'Kab. Garut', 'province_name' => 'Jawa Barat', 'postal_code' => '44116'],
                ],
                default => [],
            }]),
            default => Http::response(['message' => 'not found', 'data' => []], 404),
        };
    }

    private function ratesCalls(): array
    {
        return array_values(array_filter($this->everproCalls, fn ($c) => $c['path'] === '/shipment/v2/rates'));
    }

    private function useEverpro(): void
    {
        $this->shop->forceFill(['shipping' => ['provider' => ShippingSettings::EVERPRO] + $this->shop->shipping])->save();
        $this->inShop(fn () => CarrierAccount::query()->create([
            'provider' => 'everpro', 'sandbox' => true, 'credentials' => ['client_key' => 'ck', 'client_secret' => 'cs'],
            'settings' => ['origin_id' => 'SD-ORIGIN', 'origin_postal' => '17540', 'service' => 'JNE REG', 'shipment_type' => 'PICKUP'],
        ]));
    }

    private function quote(string $city, ?string $courier = null, ?string $postal = null, ?string $province = null): ShippingQuote
    {
        return $this->inShop(fn () => Shipping::quote($this->shop->fresh(), $city, $province, [['product_id' => $this->kaos->id, 'jumlah' => 1]], null, $courier, $postal));
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
        return ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]]];
    }

    private function channel(): WaChannel
    {
        return $this->inShop(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002', 'status' => WaChannelStatus::Connected, 'position' => 1,
            'ai_mode' => AiMode::Auto, 'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function inbound(WaChannel $channel, string $text): WaMessage
    {
        return $this->inShop(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6281234567890'), 'text', $text, null, CarbonImmutable::now(), 'Sari',
        )));
    }

    /** A lead that filled the order form: Kaos Lari to Garut, with the region dropdown's postal code. */
    private function formLead(WaChannel $channel): Lead
    {
        $this->inbound($channel, 'Halo kak');

        return $this->inShop(function () {
            $lead = Lead::query()->firstOrFail();
            $lead->forceFill(['property_id' => $this->kaos->id, 'quantity' => 1, 'shipping_province' => 'Jawa Barat',
                'shipping_address' => 'Jl. Ciledug No. 5, RT 01/RW 02, Sukamentri, Garut Kota, Kab. Garut',
                'shipping_address_parts' => ['kota' => 'Kab. Garut', 'kecamatan' => 'Garut Kota', 'kelurahan' => 'Sukamentri', 'kode_pos' => '44116', 'provinsi' => 'Jawa Barat']])->save();

            return $lead;
        });
    }

    private function toolResults(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => str_contains($r->url(), 'anthropic'))
            ->flatMap(fn (Request $r) => collect($r['messages'] ?? [])->flatMap(fn ($m) => is_array($m['content'] ?? null) ? $m['content'] : []))
            ->filter(fn ($c) => ($c['type'] ?? null) === 'tool_result')->pluck('content')->unique()->values()->all();
    }

    // ---- Primary courier ----------------------------------------------------------------------------------------

    public function test_only_the_primary_courier_is_quoted_unless_another_is_asked_for(): void
    {
        $plain = $this->quote('Bandung');
        $this->assertSame(['JNE REG' => 14000], $this->prices($plain));
        $this->assertSame('JNE REG', $plain->toArray()['kurir_utama']);
        $this->assertStringContainsString('tanpa menawarkan atau menanyakan kurir lain', $plain->toArray()['catatan_kurir']);

        // "SiCepat bisa?" prices SiCepat; "ada kurir lain?" every courier of the shop
        $this->assertSame(['SiCepat REG' => 13000], $this->prices($this->quote('Bandung', 'sicepat')));
        $this->assertSame(['JNE REG' => 14000, 'SiCepat REG' => 13000], $this->prices($this->quote('Bandung', CourierChoice::ALL)));
        $this->assertArrayNotHasKey('kurir_utama', $this->quote('Bandung', 'SiCepat REG')->toArray());

        // An expedition the shop does not use is never priced
        $jnt = $this->quote('Bandung', 'J&T');
        $this->assertSame(ShippingQuote::NEEDS_ADMIN, $jnt->status);
        $this->assertStringContainsString('J&T tidak tersedia', $jnt->message);

        // The owner may pick another primary courier
        $this->shop->forceFill(['shipping' => ['primary_courier' => 'SiCepat REG'] + $this->shop->shipping])->save();
        $this->assertSame(['SiCepat REG' => 13000], $this->prices($this->quote('Bandung')));
    }

    public function test_couriers_and_requests_for_others_are_read_from_the_buyers_words(): void
    {
        $this->assertSame(['SiCepat'], CourierChoice::fromText('SiCepat bisa?'));
        $this->assertSame(['J&T'], CourierChoice::fromText('kirim pake jnt aja kak'));
        $this->assertSame(['JNE'], CourierChoice::fromText('JNE berapa hari?'));
        $this->assertSame(CourierChoice::ALL, CourierChoice::fromText('ada kurir lain?'));
        $this->assertSame(CourierChoice::ALL, CourierChoice::fromText('ekspedisi apa aja kak'));
        $this->assertNull(CourierChoice::fromText('Ongkir ke Bandung berapa?'));
        $this->assertNull(CourierChoice::fromText('kode pos 40134 kak'));
    }

    public function test_the_ai_gets_only_the_primary_courier_when_the_buyer_did_not_ask(): void
    {
        $channel = $this->channel();
        // The model tries to list every courier on its own; the check still prices only JNE REG.
        $this->ai = [
            $this->toolUse('cek_ongkir', ['items' => [['product_id' => $this->kaos->id, 'varian' => 'L', 'jumlah' => 1]], 'kota' => 'Bandung', 'kurir' => 'semua']),
            $this->text('Ongkir ke Bandung pakai JNE REG Rp 14.000, totalnya Rp 103.000 ya Kak.'),
        ];

        $message = $this->inbound($channel, 'Kak kaos lari ongkir ke Bandung berapa?');

        $this->assertSame(AiDraft::SENT, $this->inShop(fn () => AiDraft::where('message_id', $message->getKey())->first())->status);
        $results = implode("\n", $this->toolResults());
        $this->assertStringContainsString('JNE REG', $results);
        $this->assertStringNotContainsString('SiCepat', $results);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic') && str_contains($r['system'] ?? '', 'KURIR: pakai kurir utama toko (JNE REG)'));
    }

    public function test_sicepat_bisa_prices_sicepat_and_jnt_goes_to_an_admin(): void
    {
        $channel = $this->channel();
        $this->formLead($channel);
        $this->ai = [$this->text('Bisa Kak, SiCepat REG ke Kab. Garut Rp 13.000, totalnya Rp 102.000.')];

        $message = $this->inbound($channel, 'SiCepat bisa?');

        $this->assertSame(AiDraft::SENT, $this->inShop(fn () => AiDraft::where('message_id', $message->getKey())->first())->status);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic') && str_contains($r['system'] ?? '', '"kurir":"SiCepat REG","ongkir":13000')
            && ! str_contains($r['system'] ?? '', '"kurir":"JNE REG"'));

        $jnt = $this->inbound($channel, 'Kalau J&T bisa kak?');
        $this->assertSame(AiDraft::HANDOFF, $this->inShop(fn () => AiDraft::where('message_id', $jnt->getKey())->first())->status);
    }

    public function test_catat_pesanan_without_a_courier_uses_the_primary_courier(): void
    {
        $result = $this->inShop(fn () => (new ShopTools($this->shop->fresh()))->run(ShopTools::RECORD, [
            'items' => [['product_id' => $this->kaos->id, 'varian' => 'L', 'jumlah' => 2]], 'kota' => 'Bandung', 'provinsi' => 'Jawa Barat',
            'alamat' => 'Jl. Dago No. 5, RT 01/RW 02, Coblong', 'nama' => 'Sari', 'status' => 'konfirmasi', 'pembayaran' => 'cod',
        ]));

        $this->assertSame('tercatat', $result['status']);
        $this->assertSame('JNE REG', $result['kurir']);
        $this->assertSame(14000, $result['ongkir']);

        $draft = $this->inShop(fn () => (new ShopTools($this->shop->fresh()))->run(ShopTools::RECORD, [
            'items' => [['product_id' => $this->kaos->id, 'varian' => 'L', 'jumlah' => 2]], 'kota' => 'Bandung',
            'alamat' => 'Jl. Dago No. 5, RT 01/RW 02, Coblong', 'nama' => 'Sari', 'status' => 'draft',
        ]));
        $this->assertStringContainsString('kurir JNE REG', $draft['pesan']);
        $this->assertStringContainsString('Jangan menanyakan kurir', $draft['pesan']);
    }

    public function test_the_owner_sets_the_primary_courier_and_the_source_and_existing_shops_keep_the_zone_table(): void
    {
        $this->assertSame(ShippingSettings::ZONE, ShippingSettings::of($this->shop)->provider());
        $this->assertSame('JNE REG', ShippingSettings::of($this->shop)->primaryCourier());

        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        Livewire::test(ShippingSettingsPage::class)
            ->assertSet('data.provider', ShippingSettings::ZONE)
            ->set('data.primary_courier', 'SiCepat REG')->set('data.provider', ShippingSettings::EVERPRO)
            ->call('save')->assertHasNoErrors();

        $settings = ShippingSettings::of($this->shop->fresh());
        $this->assertSame('SiCepat REG', $settings->primaryCourier());
        $this->assertSame(ShippingSettings::EVERPRO, $settings->provider());
    }

    // ---- Everpro rates ------------------------------------------------------------------------------------------

    public function test_with_everpro_the_buyer_is_quoted_everpros_rate_and_it_is_cached(): void
    {
        $this->useEverpro();

        $quote = $this->quote('Medan', null, '20111', 'Sumatera Utara');

        $this->assertSame(ShippingSettings::EVERPRO, $quote->source);
        $this->assertSame(['JNE REG' => 47000], $this->prices($quote));
        $call = $this->ratesCalls()[0]['body'];
        $this->assertSame(['17540', '20111', 1, 'PICKUP'], [$call['origin_postal_code'], $call['destination_postal_code'], $call['weight'], $call['shipment_type']]);

        // Asked again: from the cache. SiCepat comes from the same answer.
        $this->assertSame(['SiCepat REG' => 41000], $this->prices($this->quote('Medan', 'SiCepat', '20111', 'Sumatera Utara')));
        $this->assertCount(1, $this->ratesCalls());
    }

    public function test_a_lead_with_a_postal_code_is_priced_by_everpro_and_the_order_says_so(): void
    {
        $this->useEverpro();
        $lead = $this->formLead($this->channel());

        $result = $this->inShop(fn () => app(RecordOrder::class)($lead->fresh(), [
            'items' => [['product_id' => $this->kaos->id, 'varian' => 'L', 'jumlah' => 1]], 'kota' => 'Garut', 'provinsi' => 'Jawa Barat',
            'alamat' => 'Jl. Ciledug No. 5, RT 01/RW 02, Sukamentri, Garut Kota', 'pembayaran' => 'cod',
        ], User::query()->first(), 'AI'));

        $this->assertSame('tercatat', $result['status']);
        $this->assertSame(15000, $result['ongkir']);
        $this->assertSame('44116', $this->ratesCalls()[0]['body']['destination_postal_code']);

        $lead = $this->inShop(fn () => $lead->fresh());
        $this->assertSame(['JNE REG', 15000, 'everpro'], [$lead->courier, $lead->shipping_cost, $lead->shipping_cost_source]);
        $this->assertStringContainsString('Ongkir dari Everpro', $this->inShop(fn () => $lead->activities()->where('type', 'order_recorded')->value('body')));
    }

    public function test_when_everpro_fails_or_is_slow_the_zone_table_prices_it(): void
    {
        $this->useEverpro();

        $this->everpro = 'error';
        $quote = $this->quote('Medan', null, '20111', 'Sumatera Utara');
        $this->assertSame([ShippingSettings::ZONE, ['JNE REG' => 52000]], [$quote->source, $this->prices($quote)]);
        $this->assertSame('Everpro tidak bisa dihubungi', $quote->sourceNote);
        $this->assertStringContainsString('tabel zona Sumatera (Everpro tidak bisa dihubungi)', $quote->sourceLabel());

        $this->everpro = 'timeout';
        Cache::flush();
        $quote = $this->quote('Medan', null, '20112', 'Sumatera Utara');
        $this->assertSame([ShippingSettings::ZONE, ['JNE REG' => 52000]], [$quote->source, $this->prices($quote)]);
        $this->assertSame(3, config('shipping.everpro.timeout'));
    }

    public function test_an_unknown_postal_code_uses_the_zone_table_without_calling_everpro(): void
    {
        $this->useEverpro();

        $quote = $this->quote('Medan', null, null, 'Sumatera Utara');

        $this->assertSame([ShippingSettings::ZONE, ['JNE REG' => 52000]], [$quote->source, $this->prices($quote)]);
        $this->assertSame('kode pos/kelurahan tujuan belum diketahui', $quote->sourceNote);
        $this->assertSame([], $this->ratesCalls());
    }

    public function test_buat_resi_names_a_difference_with_what_the_buyer_was_told(): void
    {
        $rate = new CarrierRate('REG23', 'JNE', 'REG', 47000);
        $draft = new ShipmentDraft(rate: $rate, charged: 52000, promoCharged: 47000, recordedCharged: 52000, recordedSource: ShippingSettings::ZONE);

        $this->assertSame(['Ongkir yang disampaikan ke pembeli Rp52.000 (dari tabel zona) berbeda dari hitungan Everpro sekarang Rp47.000 (selisih Rp5.000) — pastikan sesuai kesepakatan.'], $draft->warnings());

        $same = new ShipmentDraft(rate: $rate, charged: 47000, promoCharged: 47000, recordedCharged: 47000, recordedSource: ShippingSettings::EVERPRO);
        $this->assertSame([], $same->warnings());
    }
}
