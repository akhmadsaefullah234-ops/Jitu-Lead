<?php

namespace Tests\Feature;

use App\Actions\GenerateAiReply;
use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Actions\RecordOrder;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\ShippingSettingsPage;
use App\LeadForms\FormFields;
use App\LeadForms\FormQuote;
use App\Models\CarrierAccount;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadForm;
use App\Models\Property;
use App\Models\Region;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Regions\RegionDirectory;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt J (26 Nov 2026): "Hanya pakai tarif Everpro (tanpa tarif cadangan)". With it on, buyers' prices come from
 * Everpro only: an address that is not complete enough asks for more, an address Everpro matches is priced, one
 * it does not know (or Everpro failing twice, or no courier serving it) goes to CS with the reason. The zone
 * table is never a price. Off (the default), nothing changes, the zone fallback included.
 */
class EverproStrictTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private Property $tas;

    /** @var list<array{path: string, body: array}> */
    private array $everproCalls = [];

    /** 'ok', 'error', 'timeout', 'flaky' (fails once, then answers), 'sicepat' (only SiCepat serves it) or 'none'. */
    private string $everpro = 'ok';

    private int $rateTries = 0;

    /** @var list<array> */
    private array $ai = [];

    private int $n = 0;

    /** The buyer's place in Everpro's list (Kel. Bontorannu, Kec. Mariso, Kota Makassar). */
    private const PLACE = ['id' => 'SD-BONTO', 'subDistrict' => 'Bontorannu', 'district' => 'Mariso', 'city' => 'Kota Makassar', 'province' => 'Sulawesi Selatan', 'postalCode' => '90121'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->tas = $this->in(fn () => Property::create(['name' => 'Tas Badminton', 'price_from' => 199000, 'weight_grams' => 300, 'shipping_promos' => [
            ['type' => 'discount', 'amount' => 30000, 'area' => 'outside_java_bali', 'min_quantity' => 1],
        ]]));

        foreach ([
            ['73', 'Sulawesi Selatan', 1, null, null], ['73.71', 'Kota Makassar', 2, '73', null], ['73.71.01', 'Mariso', 3, '73.71', null],
            ['73.71.01.1001', 'Bontorannu', 4, '73.71.01', '90121'],
        ] as [$code, $name, $level, $parent, $postal]) {
            Region::query()->insert(['code' => $code, 'name' => $name, 'level' => $level, 'parent_code' => $parent, 'postal_code' => $postal, 'search' => RegionDirectory::searchKey($name)]);
        }
        RegionDirectory::flush();

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

    private function in(callable $fn, ?Tenant $tenant = null): mixed
    {
        return app(CurrentTenant::class)->run($tenant ?? $this->shop, $fn);
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

        if ($path === '/shipment/v2/rates' && $this->everpro === 'flaky' && ++$this->rateTries === 1) {
            throw new ConnectionException('cURL error 28: Operation timed out after 3000 milliseconds');
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
            '/shipment/v2/rates' => Http::response(['data' => ['regular' => match ($this->everpro) {
                'none' => [],
                'sicepat' => [$rate('SiCepat', 'REG', 58000)],
                default => [$rate('JNE', 'REG23', 71000), $rate('SiCepat', 'REG', 58000)],
            }]]),
            '/location/v1/postalcode' => Http::response(['data' => ($r->data()['postal_code'] ?? null) === '90121' ? [[
                'sub_district_id' => 'SD-BONTO', 'sub_district_name' => 'Bontorannu', 'district_name' => 'Mariso', 'city_name' => 'Kota Makassar',
                'province_name' => 'Sulawesi Selatan', 'postal_code' => '90121',
            ]] : []]),
            default => Http::response(['message' => 'not found', 'data' => []], 404),
        };
    }

    private function rates(): array
    {
        return array_values(array_filter($this->everproCalls, fn ($c) => $c['path'] === '/shipment/v2/rates'));
    }

    private function everproAccount(?Tenant $tenant = null): void
    {
        $this->in(fn () => CarrierAccount::query()->create([
            'provider' => 'everpro', 'sandbox' => true, 'credentials' => ['client_key' => 'ck', 'client_secret' => 'cs'],
            'settings' => ['origin_id' => 'SD-ORIGIN', 'origin_postal' => '17540', 'service' => 'JNE REG', 'shipment_type' => 'PICKUP'],
        ]), $tenant);
    }

    /** Price source Everpro, with "Hanya pakai tarif Everpro" on or off. */
    private function source(bool $strict, ?Tenant $tenant = null): void
    {
        $tenant ??= $this->shop;
        $tenant->forceFill(['shipping' => ['provider' => ShippingSettings::EVERPRO, 'everpro_strict' => $strict] + $tenant->shipping])->save();
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
            'in-'.++$this->n, PhoneNumber::normalize('6289900005678'), 'text', $text, null, CarbonImmutable::now(), 'Rina',
        )));
    }

    /**
     * A buyer in Kota Makassar. $parts is the address as the lead keeps it; $state its Everpro match (null: not
     * matched), with $candidates as the places to choose from.
     */
    private function lead(WaChannel $channel, array $parts, ?string $state, array $candidates = [self::PLACE], ?string $note = null): Lead
    {
        $this->says($channel, 'Halo kak');

        return $this->in(function () use ($channel, $parts, $state, $candidates, $note) {
            $lead = Lead::query()->firstOrFail();
            $lead->forceFill(['name' => 'Rina', 'property_id' => $this->tas->id, 'quantity' => 1, 'variant' => 'Hitam', 'shipping_province' => 'Sulawesi Selatan',
                'shipping_address' => 'Jl. Nusantara No. 5, RT 002/RW 001, Makassar', 'shipping_address_parts' => $parts + ['provinsi' => 'Sulawesi Selatan']])->save();

            if ($state !== null) {
                $lead->forceFill(['provider_location_state' => $state, 'provider_location_id' => $state === ProviderLocations::EXACT ? $candidates[0]['id'] : null,
                    'provider_location_label' => 'Bontorannu, Mariso, Kota Makassar', 'provider_location_candidates' => $candidates,
                    'provider_location_note' => $note, 'provider_location_key' => ProviderLocations::key($lead)])->save();
            }
            $channel->forceFill(['ai_mode' => AiMode::Auto])->save();

            return $lead->fresh();
        });
    }

    private function exactLead(?WaChannel $channel = null): Lead
    {
        return $this->lead($channel ?? $this->channel(), ['kota' => 'Kota Makassar', 'kecamatan' => 'Mariso', 'kelurahan' => 'Bontorannu', 'kode_pos' => '90121'], ProviderLocations::EXACT);
    }

    private function quote(?Lead $lead, string $city = 'Kota Makassar', ?string $province = 'Sulawesi Selatan', ?string $district = null, ?string $postal = null,
        ?string $subDistrict = null, ?Tenant $tenant = null): ShippingQuote
    {
        $tenant ??= $this->shop;

        return $this->in(fn () => Shipping::quote($tenant->fresh(), $city, $province, [['product_id' => $this->tas->id, 'jumlah' => 1]], $district, null, $postal, $subDistrict, $lead), $tenant);
    }

    private function handoffs(): array
    {
        return $this->in(fn () => LeadActivity::query()->where('type', 'ai_handoff')->pluck('body')->all());
    }

    private function sent(): array
    {
        return $this->in(fn () => WaMessage::query()->where('direction', 'out')->orderBy('id')->pluck('body')->all());
    }

    private function record(Lead $lead): array
    {
        return $this->in(fn () => app(RecordOrder::class)($lead->fresh(), [
            'items' => [['product_id' => $this->tas->id, 'varian' => 'Hitam', 'jumlah' => 1]], 'kota' => 'Kota Makassar', 'provinsi' => 'Sulawesi Selatan',
            'alamat' => 'Jl. Nusantara No. 5, RT 002/RW 001, Kel. Bontorannu', 'status' => 'konfirmasi', 'pembayaran' => 'cod', 'nama' => 'Rina',
        ], $this->shop->users()->first(), 'AI'));
    }

    public function test_off_by_default_and_off_keeps_the_zone_table_fallback(): void
    {
        $this->assertFalse(ShippingSettings::of($this->shop)->everproStrict());
        $this->assertFalse(ShippingSettings::blank()['everpro_strict']);

        // Everpro as the price source, the setting off: no account yet, so the zone table prices it, as before.
        $this->source(false);
        $zone = $this->quote(null, district: 'Mariso');
        $this->assertTrue($zone->ok());
        $this->assertSame([ShippingSettings::ZONE, 'akun Everpro belum aktif', 32000], [$zone->source, $zone->sourceNote, $zone->options[0]->price]);

        // Everpro slow: still the zone table, Everpro asked once.
        $this->everproAccount();
        $this->everpro = 'timeout';
        $slow = $this->quote(null, postal: '90121');
        $this->assertSame([ShippingQuote::OK, ShippingSettings::ZONE, 'Everpro tidak bisa dihubungi'], [$slow->status, $slow->source, $slow->sourceNote]);
        $this->assertCount(1, $this->rates());

        // The flag alone does nothing with the zone table as the price source.
        $this->shop->forceFill(['shipping' => ['provider' => ShippingSettings::ZONE, 'everpro_strict' => true] + $this->shop->shipping])->save();
        $this->assertFalse(ShippingSettings::of($this->shop->fresh())->everproStrict());
        $this->assertSame(ShippingSettings::ZONE, $this->quote(null)->source);
    }

    public function test_a_matched_address_is_priced_from_everpro_with_the_shipping_promo(): void
    {
        $this->everproAccount();
        $this->source(true);
        $lead = $this->exactLead();

        $quote = $this->quote($lead);

        $this->assertTrue($quote->ok());
        $this->assertSame([ShippingSettings::EVERPRO, null, 'JNE REG'], [$quote->source, $quote->zone, $quote->options[0]->courier]);
        $this->assertSame([71000, 41000], [$quote->options[0]->normalPrice, $quote->options[0]->price]);
        $this->assertSame(['17540', '90121'], [$this->rates()[0]['body']['origin_postal_code'], $this->rates()[0]['body']['destination_postal_code']]);

        // No lead (Inbox, a form): the postal code given is priced.
        $this->assertSame(41000, $this->quote(null, postal: '90121')->options[0]->price);
    }

    public function test_an_incomplete_address_asks_for_kecamatan_and_kelurahan_with_no_amount(): void
    {
        $this->everproAccount();
        $this->source(true);

        // Only the city: no kecamatan, kelurahan or postal code.
        $lead = $this->lead($this->channel(), ['kota' => 'Kota Makassar'], null);
        $quote = $this->quote($lead);

        $this->assertSame(ShippingQuote::UNCLEAR_DESTINATION, $quote->status);
        $this->assertSame(EverproShippingRates::STRICT_UNCLEAR, $quote->message);
        $this->assertSame([], $quote->options);
        $this->assertNull($quote->adminReason);
        $this->assertSame([], $this->rates(), 'Everpro is not asked for a price');
        $this->assertStringNotContainsString('Rp', json_encode($quote->toArray()));

        // Matched before as "no postal code or kelurahan", or no city at all: the same question.
        $this->assertSame(ShippingQuote::UNCLEAR_DESTINATION, $this->quote(null)->status);
        $this->assertSame(ShippingQuote::UNCLEAR_DESTINATION, $this->quote(null, city: '', province: null)->status);
    }

    public function test_the_ai_asks_for_the_address_and_does_not_hand_over(): void
    {
        $this->everproAccount();
        $this->source(true);
        $channel = $this->channel();
        $this->lead($channel, ['kota' => 'Kota Makassar'], ProviderLocations::NONE, [], 'alamat belum ada kode pos atau kelurahan');
        $this->ai = [
            ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'cek_ongkir', 'input' => ['items' => [['product_id' => $this->tas->id, 'jumlah' => 1]], 'kota' => 'Kota Makassar']]]],
            $this->text('Boleh info kecamatan dan kelurahannya kak? 😊'),
        ];

        $this->says($channel, 'Ongkir ke Makassar berapa kak?');

        $this->assertSame([], $this->handoffs());
        $this->assertNotContains(GenerateAiReply::SHIPPING_CHECK_REPLY, $this->sent());
        $this->assertContains('Boleh info kecamatan dan kelurahannya kak? 😊', $this->sent());
    }

    public function test_an_uncertain_kelurahan_is_priced_when_its_kecamatan_and_city_match(): void
    {
        $this->everproAccount();
        $this->source(true);
        $other = ['id' => 'SD-LAIN', 'subDistrict' => 'Lette', 'district' => 'Mariso', 'city' => 'Kota Makassar', 'province' => 'Sulawesi Selatan', 'postalCode' => '90121'];
        $lead = $this->lead($this->channel(), ['kota' => 'Kota Makassar', 'kecamatan' => 'Mariso', 'kelurahan' => 'Bontoranu'], ProviderLocations::MULTIPLE, [self::PLACE, $other]);

        $quote = $this->quote($lead);

        $this->assertTrue($quote->ok());
        $this->assertSame([ShippingSettings::EVERPRO, 41000], [$quote->source, $quote->options[0]->price]);
        $this->assertSame('90121', $this->rates()[0]['body']['destination_postal_code']);
    }

    public function test_several_postal_codes_in_the_kecamatan_ask_for_the_kelurahan(): void
    {
        $this->everproAccount();
        $this->source(true);
        $other = ['id' => 'SD-LAIN', 'subDistrict' => 'Lette', 'district' => 'Mariso', 'city' => 'Kota Makassar', 'province' => 'Sulawesi Selatan', 'postalCode' => '90122'];
        $lead = $this->lead($this->channel(), ['kota' => 'Kota Makassar', 'kecamatan' => 'Mariso', 'kelurahan' => 'Bonto'], ProviderLocations::MULTIPLE, [self::PLACE, $other]);

        $this->assertSame(ShippingQuote::UNCLEAR_DESTINATION, $this->quote($lead)->status);
        $this->assertSame([], $this->rates());
    }

    public function test_an_address_everpro_does_not_know_goes_to_cs_with_the_reason(): void
    {
        $this->everproAccount();
        $this->source(true);
        $channel = $this->channel();
        $lead = $this->lead($channel, ['kota' => 'Kota Makassar', 'kecamatan' => 'Tamalate', 'kelurahan' => 'Entah'], ProviderLocations::NONE, [], 'tidak ada lokasi yang cocok');

        $quote = $this->quote($lead);
        $this->assertSame(ShippingQuote::NEEDS_ADMIN, $quote->status);
        $this->assertSame(EverproShippingRates::ADMIN_PLACE_NOT_FOUND, $quote->adminReason);
        $this->assertSame([], $quote->options);

        $this->says($channel, 'Ongkir ke alamat saya berapa kak?');

        $this->assertContains(GenerateAiReply::SHIPPING_CHECK_REPLY, $this->sent());
        $this->assertSame(['AI menyerahkan chat ke agen: '.GenerateAiReply::SHIPPING_ADMIN.': '.EverproShippingRates::ADMIN_PLACE_NOT_FOUND], $this->handoffs());
    }

    public function test_a_kecamatan_with_no_place_another_province_or_an_unknown_city_go_to_cs(): void
    {
        $this->everproAccount();
        $this->source(true);
        $channel = $this->channel();

        // Candidates only in another kecamatan.
        $elsewhere = ['id' => 'SD-X', 'subDistrict' => 'Maccini', 'district' => 'Makassar', 'city' => 'Kota Makassar', 'province' => 'Sulawesi Selatan', 'postalCode' => '90144'];
        $lead = $this->lead($channel, ['kota' => 'Kota Makassar', 'kecamatan' => 'Mariso', 'kelurahan' => 'Bonto'], ProviderLocations::MULTIPLE, [$elsewhere]);
        $this->assertSame(EverproShippingRates::ADMIN_PLACE_NOT_FOUND, $this->quote($lead)->adminReason);

        // Everpro's place is in another province.
        $this->in(fn () => $lead->forceFill(['provider_location_state' => ProviderLocations::EXACT, 'provider_location_id' => 'SD-BONTO',
            'provider_location_candidates' => [['province' => 'Sulawesi Barat'] + self::PLACE]])->save());
        $province = $this->quote($lead->fresh());
        $this->assertSame(ShippingQuote::NEEDS_ADMIN, $province->status);
        $this->assertStringContainsString('provinsi', (string) $province->adminReason);

        // A city no region list knows.
        $unknown = $this->quote(null, city: 'Kota Antahberantah', province: null, district: 'Somewhere');
        $this->assertSame([ShippingQuote::NEEDS_ADMIN, EverproShippingRates::ADMIN_PLACE_NOT_FOUND], [$unknown->status, $unknown->adminReason]);
        $this->assertSame([], $this->rates());
    }

    public function test_everpro_failing_is_tried_once_more_then_goes_to_cs(): void
    {
        $this->everproAccount();
        $this->source(true);
        $channel = $this->channel();
        $lead = $this->exactLead($channel);

        $this->everpro = 'timeout';
        $quote = $this->quote($lead);
        $this->assertSame([ShippingQuote::NEEDS_ADMIN, EverproShippingRates::ADMIN_EVERPRO_FAILED], [$quote->status, $quote->adminReason]);
        $this->assertCount(2, $this->rates(), 'Tried once more');
        $this->assertSame([], $quote->options, 'No zone price');

        $this->everpro = 'error';
        $this->assertSame(EverproShippingRates::ADMIN_EVERPRO_FAILED, $this->quote($lead)->adminReason);

        $this->everpro = 'timeout';
        $this->says($channel, 'Ongkir ke alamat saya berapa kak?');
        $this->assertContains(GenerateAiReply::SHIPPING_CHECK_REPLY, $this->sent());
        $this->assertSame(['AI menyerahkan chat ke agen: '.GenerateAiReply::SHIPPING_ADMIN.': '.EverproShippingRates::ADMIN_EVERPRO_FAILED], $this->handoffs());
    }

    public function test_a_second_try_that_answers_is_priced(): void
    {
        $this->everproAccount();
        $this->source(true);
        $this->everpro = 'flaky';

        $quote = $this->quote($this->exactLead());

        $this->assertTrue($quote->ok());
        $this->assertSame(41000, $quote->options[0]->price);
        $this->assertCount(2, $this->rates());
    }

    public function test_another_shop_courier_when_the_primary_one_does_not_serve_the_place(): void
    {
        $this->everproAccount();
        $this->source(true);
        $lead = $this->exactLead();

        $this->everpro = 'sicepat';
        $quote = $this->quote($lead);
        $this->assertTrue($quote->ok());
        $this->assertSame(['SiCepat REG', 28000, 'SiCepat REG'], [$quote->options[0]->courier, $quote->options[0]->price, $quote->primaryCourier]);

        $this->everpro = 'none';
        Cache()->flush();
        $none = $this->quote($lead);
        $this->assertSame([ShippingQuote::NEEDS_ADMIN, EverproShippingRates::ADMIN_NO_SERVICE], [$none->status, $none->adminReason]);
    }

    public function test_the_zone_table_is_never_a_price_and_konfirmasi_admin_zones_are_unchanged(): void
    {
        $this->source(true);

        // No Everpro account: no zone price, a person checks it.
        $quote = $this->quote(null, postal: '90121');
        $this->assertSame([ShippingQuote::NEEDS_ADMIN, EverproShippingRates::ADMIN_NO_ACCOUNT], [$quote->status, $quote->adminReason]);

        // NTT ("konfirmasi admin"): as before, an admin, Everpro not asked for a lead-less quote.
        $this->everproAccount();
        $ntt = $this->quote(null, city: 'Kab. Manggarai', province: 'Nusa Tenggara Timur', postal: '86511');
        $this->assertSame([ShippingQuote::NEEDS_ADMIN, 'NTB, NTT, Maluku, Papua', EverproShippingRates::ADMIN_PLACE_UNCERTAIN], [$ntt->status, $ntt->zone, $ntt->adminReason]);
        $this->assertSame([], $this->rates());
    }

    public function test_a_cost_cs_typed_is_never_priced_over_or_blocked(): void
    {
        $this->everproAccount();
        $this->source(true);
        $lead = $this->exactLead();
        // CS typed the cost on the lead: no source.
        $this->in(fn () => $lead->forceFill(['courier' => 'JNE REG', 'shipping_cost' => 25000])->save());
        $this->assertNull($this->in(fn () => $lead->fresh()->shipping_cost_source));

        $this->everpro = 'timeout';
        $result = $this->record($lead);

        $this->assertSame('tercatat', $result['status']);
        $fresh = $this->in(fn () => $lead->fresh());
        $this->assertSame([25000, null], [$fresh->shipping_cost, $fresh->shipping_cost_source]);
        $this->assertStringContainsString('Ongkir dari isian manual CS', $this->in(fn () => $fresh->activities()->where('type', 'order_recorded')->value('body')));

        // Everpro answering does not change it either.
        $this->everpro = 'ok';
        $this->record($lead);
        $this->assertSame(25000, $this->in(fn () => $lead->fresh()->shipping_cost));
    }

    public function test_a_cost_the_buyer_agreed_to_is_kept_when_everpro_fails(): void
    {
        $this->everproAccount();
        $this->source(true);
        $lead = $this->exactLead();
        $this->assertSame('tercatat', $this->record($lead)['status']);
        $this->assertSame([41000, 'everpro'], $this->in(fn () => [$lead->fresh()->shipping_cost, $lead->fresh()->shipping_cost_source]));

        // The buyer changes the payment later while Everpro is down: the order is not blocked.
        $this->everpro = 'timeout';
        Cache()->flush();
        $this->assertSame('tercatat', $this->record($lead)['status']);
        $this->assertSame([41000, 'everpro'], $this->in(fn () => [$lead->fresh()->shipping_cost, $lead->fresh()->shipping_cost_source]));
    }

    public function test_the_order_form_prices_from_everpro_or_says_it_is_confirmed_on_whatsapp(): void
    {
        $this->everproAccount();
        $this->source(true);
        $form = $this->in(fn () => LeadForm::create([
            'name' => 'Tas Badminton (Form Pendek)', 'slug' => 'pendek', 'status' => 'active', 'design' => ['title' => 'Order'], 'property_id' => $this->tas->id,
            'fields' => FormFields::normalize([
                ['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'required' => true, 'maps_to' => 'name'],
                ['key' => 'wa', 'type' => 'phone', 'label' => 'WhatsApp', 'required' => true, 'maps_to' => 'phone'],
                ['key' => 'alamat', 'type' => 'address', 'label' => 'Alamat', 'required' => true, 'maps_to' => 'shipping_address', 'mode' => 'region',
                    'parts' => ['street', 'kota', 'kecamatan', 'kelurahan', 'kode_pos'], 'required_parts' => ['street', 'kota', 'kecamatan', 'kelurahan'],
                    'address_options' => ['hide_postal', 'city_first']],
            ]),
            'submit' => ['label' => 'Pesan'], 'after_submit' => ['type' => 'message', 'message' => 'Terima kasih.'],
        ]));
        $price = fn (array $address) => $this->in(fn () => FormQuote::for($this->shop->fresh(), $form, $address, 1));

        $this->assertSame('Ongkir dihitung setelah kecamatan dan kelurahan dipilih.', $price(['kota' => '73.71'])['pesan']);
        $this->assertSame(41000, $price(['kota' => '73.71', 'kecamatan' => '73.71.01', 'kelurahan' => '73.71.01.1001'])['ongkir']);

        Cache()->flush();
        $this->everpro = 'timeout';
        $failed = $price(['kota' => '73.71', 'kecamatan' => '73.71.01', 'kelurahan' => '73.71.01.1001']);
        $this->assertSame('Ongkir akan dikonfirmasi lewat WhatsApp.', $failed['pesan']);
        $this->assertArrayNotHasKey('ongkir', $failed);
    }

    public function test_one_shop_turning_it_on_changes_no_other_shop(): void
    {
        $other = app(ProvisionTenant::class)('Toko Lain', 'toko-lain', User::factory()->create(), 'retail');
        $other->forceFill(['shipping' => $this->shop->shipping])->save();
        $this->source(false, $other);
        $this->source(true);

        $this->assertTrue(ShippingSettings::of($this->shop->fresh())->everproStrict());
        $this->assertFalse(ShippingSettings::of($other->fresh())->everproStrict());

        // Neither has an Everpro account: the strict shop asks CS, the other keeps its zone price.
        $this->assertSame(ShippingQuote::NEEDS_ADMIN, $this->quote(null, postal: '90121')->status);
        $bag = $this->in(fn () => Property::create(['name' => 'Tas Lain', 'price_from' => 150000, 'weight_grams' => 300]), $other);
        $theirs = $this->in(fn () => Shipping::quote($other->fresh(), 'Kota Makassar', 'Sulawesi Selatan', [['product_id' => $bag->id, 'jumlah' => 1]], null, null, '90121'), $other);
        $this->assertSame([ShippingQuote::OK, ShippingSettings::ZONE, 62000], [$theirs->status, $theirs->source, $theirs->options[0]->price]);
    }

    public function test_an_admin_turns_it_on_in_the_shipping_settings(): void
    {
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(ShippingSettingsPage::class)
            ->assertDontSee('Hanya pakai tarif Everpro')
            ->set('data.provider', ShippingSettings::EVERPRO)
            ->assertSee('Hanya pakai tarif Everpro (tanpa tarif cadangan)')
            ->set('data.everpro_strict', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(ShippingSettings::of($this->shop->fresh())->everproStrict());

        // Back to the zone table: the flag is not kept.
        Livewire::test(ShippingSettingsPage::class)->set('data.provider', ShippingSettings::ZONE)->call('save')->assertHasNoErrors();
        $this->assertFalse($this->shop->fresh()->shipping['everpro_strict']);
    }
}
