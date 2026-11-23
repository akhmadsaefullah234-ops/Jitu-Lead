<?php

namespace Tests\Feature;

use App\Actions\GenerateAiReply;
use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Actions\RecordOrder;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Models\AiDraft;
use App\Models\CarrierAccount;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Shipping\EverproShippingRates;
use App\Shipping\Shipments\ProviderLocations;
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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pekerjaan 3 (23 Nov 2026): a "konfirmasi admin" zone (NTB, NTT, Maluku, Papua) is priced from Everpro for the
 * lead's own address once its Everpro place is certain, with the shop's shipping promo, and no handoff. When
 * Everpro fails, the place is uncertain or there is no service, the flag still hands it over, with the reason
 * and a "sedang dicek" reply. Java keeps the zone table; a shop without Everpro is unchanged (lead #1024).
 */
class EverproAdminZoneTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private Property $tas;

    /** @var list<array{path: string, body: array}> */
    private array $everproCalls = [];

    /** 'ok', 'error', 'timeout' or 'none' (no service to the destination). */
    private string $everpro = 'ok';

    /** @var list<array> */
    private array $ai = [];

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->tas = $this->in(fn () => Property::create(['name' => 'Tas Badminton', 'price_from' => 199000, 'weight_grams' => 300, 'shipping_promos' => [
            ['type' => 'discount', 'amount' => 30000, 'area' => 'outside_java_bali', 'min_quantity' => 1],
        ]]));

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

    private function in(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    private function text(string $text): array
    {
        return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]];
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
            'price' => $price, 'shipment_price_after_discount' => $price, 'surcharge' => 0, 'min_duration' => 3, 'max_duration' => 6, 'signed_key' => 'sig-'.$code];

        return match ($path) {
            '/shipment/v2/rates' => Http::response(['data' => $this->everpro === 'none' ? ['regular' => []]
                : ['regular' => [$rate('JNE', 'REG23', 62000), $rate('SiCepat', 'REG', 58000)]]]),
            default => Http::response(['message' => 'not found', 'data' => []], 404),
        };
    }

    private function rates(): array
    {
        return array_values(array_filter($this->everproCalls, fn ($c) => $c['path'] === '/shipment/v2/rates'));
    }

    private function everproAccount(): void
    {
        $this->in(fn () => CarrierAccount::query()->create([
            'provider' => 'everpro', 'sandbox' => true, 'credentials' => ['client_key' => 'ck', 'client_secret' => 'cs'],
            'settings' => ['origin_id' => 'SD-ORIGIN', 'origin_postal' => '17540', 'service' => 'JNE REG', 'shipment_type' => 'PICKUP'],
        ]));
    }

    private function channel(): WaChannel
    {
        return $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002', 'status' => WaChannelStatus::Connected, 'position' => 1,
            'ai_mode' => AiMode::Off, 'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function says(WaChannel $channel, string $text): WaMessage
    {
        return $this->in(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6289900005678'), 'text', $text, null, CarbonImmutable::now(), 'Yosef',
        )));
    }

    /** #1024: a buyer in Ruteng, Kab. Manggarai (NTT), whose address Everpro knows for certain (or not). */
    private function nttLead(WaChannel $channel, string $state = ProviderLocations::EXACT): Lead
    {
        $this->says($channel, 'Halo kak');

        return $this->in(function () use ($channel, $state) {
            $lead = Lead::query()->firstOrFail();
            $lead->forceFill(['name' => 'Yosef', 'property_id' => $this->tas->id, 'quantity' => 1, 'variant' => 'Hitam', 'shipping_province' => 'Nusa Tenggara Timur',
                'shipping_address' => 'Jl. Ahmad Yani No. 7, RT 001/RW 002, Kel. Wali, Kec. Langke Rembong, Kab. Manggarai, Nusa Tenggara Timur 86511',
                'shipping_address_parts' => ['kota' => 'Kab. Manggarai', 'kecamatan' => 'Langke Rembong', 'kelurahan' => 'Wali', 'kode_pos' => '86511', 'provinsi' => 'Nusa Tenggara Timur']])->save();
            $place = ['id' => 'SD-WALI', 'subDistrict' => 'Wali', 'district' => 'Langke Rembong', 'city' => 'Kab. Manggarai', 'province' => 'Nusa Tenggara Timur', 'postalCode' => '86511'];
            $lead->forceFill(['provider_location_state' => $state, 'provider_location_id' => $state === ProviderLocations::EXACT ? 'SD-WALI' : null,
                'provider_location_label' => 'Wali, Langke Rembong, Kab. Manggarai', 'provider_location_candidates' => [$place], 'provider_location_key' => ProviderLocations::key($lead)])->save();
            $channel->forceFill(['ai_mode' => AiMode::Auto])->save();

            return $lead->fresh();
        });
    }

    private function quote(?Lead $lead, string $city = 'Kab. Manggarai', string $province = 'Nusa Tenggara Timur'): ShippingQuote
    {
        return $this->in(fn () => Shipping::quote($this->shop->fresh(), $city, $province, [['product_id' => $this->tas->id, 'jumlah' => 1]], null, null, null, null, $lead));
    }

    private function handoffs(): array
    {
        return $this->in(fn () => LeadActivity::query()->where('type', 'ai_handoff')->pluck('body')->all());
    }

    private function sent(): array
    {
        return $this->in(fn () => WaMessage::query()->where('direction', 'out')->orderBy('id')->pluck('body')->all());
    }

    public function test_ntt_is_priced_from_everpro_with_the_shipping_promo(): void
    {
        $this->everproAccount();
        $lead = $this->nttLead($this->channel());

        $quote = $this->quote($lead);

        $this->assertTrue($quote->ok());
        $this->assertSame(ShippingSettings::EVERPRO, $quote->source);
        $this->assertSame('JNE REG', $quote->options[0]->courier);
        $this->assertSame([62000, 32000], [$quote->options[0]->normalPrice, $quote->options[0]->price]);
        $this->assertStringContainsString('luar Jawa & Bali', (string) $quote->options[0]->promo);
        $call = $this->rates()[0]['body'];
        $this->assertSame(['17540', '86511', 1], [$call['origin_postal_code'], $call['destination_postal_code'], $call['weight']]);
    }

    public function test_the_ai_quotes_and_records_an_ntt_order_without_an_agent(): void
    {
        $this->everproAccount();
        $channel = $this->channel();
        $lead = $this->nttLead($channel);
        $this->ai = [$this->text('Ongkir JNE REG ke Kab. Manggarai Rp 32.000 (normal Rp 62.000, potongan Rp 30.000 luar Jawa & Bali), total Rp 231.000 kak 😊')];

        $ask = $this->says($channel, 'Ongkir ke alamat saya berapa kak?');

        $this->assertSame(AiDraft::SENT, $this->in(fn () => AiDraft::query()->where('message_id', $ask->id)->value('status')));
        $this->assertSame([], $this->handoffs());

        $result = $this->in(fn () => app(RecordOrder::class)($lead->fresh(), [
            'items' => [['product_id' => $this->tas->id, 'varian' => 'Hitam', 'jumlah' => 1]], 'kota' => 'Kab. Manggarai', 'provinsi' => 'Nusa Tenggara Timur',
            'alamat' => 'Jl. Ahmad Yani No. 7, RT 001/RW 002, Kel. Wali', 'status' => 'konfirmasi', 'pembayaran' => 'cod', 'nama' => 'Yosef',
        ], $this->shop->users()->first(), 'AI'));

        $this->assertSame('tercatat', $result['status']);
        $fresh = $this->in(fn () => $lead->fresh());
        $this->assertSame([32000, 'everpro', 'Dikemas & dikirim'], [$fresh->shipping_cost, $fresh->shipping_cost_source, $this->in(fn () => $fresh->stage?->name)]);
        $this->assertStringContainsString('Ongkir dari Everpro', $this->in(fn () => $fresh->activities()->where('type', 'order_recorded')->value('body')));
    }

    public function test_when_everpro_fails_the_buyer_hears_it_is_being_checked_and_the_agent_sees_why(): void
    {
        $this->everproAccount();
        $this->everpro = 'timeout';
        $channel = $this->channel();
        $lead = $this->nttLead($channel);

        $quote = $this->quote($lead);
        $this->assertSame(ShippingQuote::NEEDS_ADMIN, $quote->status);
        $this->assertSame(EverproShippingRates::ADMIN_EVERPRO_FAILED, $quote->adminReason);

        $this->says($channel, 'Ongkir ke alamat saya berapa kak?');

        $this->assertContains(GenerateAiReply::SHIPPING_CHECK_REPLY, $this->sent());
        $this->assertSame(['AI menyerahkan chat ke agen: '.GenerateAiReply::SHIPPING_ADMIN.': '.EverproShippingRates::ADMIN_EVERPRO_FAILED], $this->handoffs());
    }

    public function test_an_error_or_no_service_also_goes_to_an_admin_with_the_reason(): void
    {
        $this->everproAccount();
        $lead = $this->nttLead($this->channel());

        $this->everpro = 'error';
        $this->assertSame(EverproShippingRates::ADMIN_EVERPRO_FAILED, $this->quote($lead)->adminReason);

        $this->everpro = 'none';
        $none = $this->quote($lead);
        $this->assertSame(ShippingQuote::NEEDS_ADMIN, $none->status);
        $this->assertSame(EverproShippingRates::ADMIN_NO_SERVICE, $none->adminReason);
    }

    public function test_an_uncertain_place_or_another_city_is_not_priced(): void
    {
        $this->everproAccount();
        $lead = $this->nttLead($this->channel(), ProviderLocations::MULTIPLE);

        $quote = $this->quote($lead);
        $this->assertSame(ShippingQuote::NEEDS_ADMIN, $quote->status);
        $this->assertSame(EverproShippingRates::ADMIN_PLACE_UNCERTAIN, $quote->adminReason);
        $this->assertSame([], $this->rates());

        // A quote for a city that is not the lead's own address, and a quote without a lead.
        $this->in(fn () => $lead->forceFill(['provider_location_state' => ProviderLocations::EXACT, 'provider_location_id' => 'SD-WALI'])->save());
        $this->assertSame(EverproShippingRates::ADMIN_PLACE_UNCERTAIN, $this->quote($lead->fresh(), 'Kota Kupang')->adminReason);
        $this->assertSame(EverproShippingRates::ADMIN_PLACE_UNCERTAIN, $this->quote(null)->adminReason);
        $this->assertSame([], $this->rates());
        $this->assertTrue(collect(ShippingSettings::of($this->shop->fresh())->zones())->firstWhere('name', 'NTB, NTT, Maluku, Papua')['confirm_admin']);
    }

    public function test_java_keeps_the_zone_table_and_a_shop_without_everpro_is_unchanged(): void
    {
        $this->everproAccount();
        $java = $this->quote(null, 'Kota Bandung', 'Jawa Barat');
        $this->assertTrue($java->ok());
        $this->assertSame(ShippingSettings::ZONE, $java->source);
        $this->assertSame([], $this->rates());

        // Without an Everpro account: "konfirmasi admin" exactly as before, a silent handoff without a reason.
        $this->in(fn () => CarrierAccount::query()->delete());
        $channel = $this->channel();
        $lead = $this->nttLead($channel);
        $quote = $this->quote($lead);
        $this->assertSame(ShippingQuote::NEEDS_ADMIN, $quote->status);
        $this->assertNull($quote->adminReason);
        $this->assertSame('Ongkir ke zona NTB, NTT, Maluku, Papua perlu dikonfirmasi admin.', $quote->message);

        $this->says($channel, 'Ongkir ke alamat saya berapa kak?');
        $this->assertSame(['AI menyerahkan chat ke agen: '.GenerateAiReply::SHIPPING_ADMIN], $this->handoffs());
        $this->assertNotContains(GenerateAiReply::SHIPPING_CHECK_REPLY, $this->sent());
        $this->assertSame([], $this->rates());
    }

    public function test_a_property_agency_is_unchanged(): void
    {
        $griya = app(ProvisionTenant::class)('Griya Contoh', 'griya-contoh', User::factory()->create());
        $quote = app(CurrentTenant::class)->run($griya, fn () => Shipping::quote($griya, 'Kab. Manggarai', 'Nusa Tenggara Timur', [['produk' => 'Rumah', 'jumlah' => 1]]));

        $this->assertSame(ShippingQuote::NOT_AVAILABLE, $quote->status);
        $this->assertNull($quote->adminReason);
    }
}
