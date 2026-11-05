<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Automation\RetailStages;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\ShippingSettingsPage;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Mail\ShipmentNeedsAction;
use App\Models\AiDraft;
use App\Models\CarrierAccount;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Shipment;
use App\Models\ShipmentNotice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Carriers\ShipmentStatus;
use App\Shipping\Shipments\Shipments;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Automatic shipping through Everpro (App\Shipping\Carriers): booking, tracking numbers, labels, callbacks,
 * the buyer's status messages, automatic stages and the AI. Everpro is faked; no real credentials anywhere.
 */
class EverproShippingTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'fake-client-key';

    private const SECRET = 'fake-client-secret';

    private const CALLBACK_KEY = 'fake-callback-key';

    private Tenant $shop;

    private Property $bag;

    /** @var list<array{method: string, path: string, body: array}> calls Everpro received */
    private array $calls = [];

    private int $tokens = 0;

    private int $n = 0;

    /** Next answers for GET .../track and POST /orders/track */
    private array $track = [];

    private array $ai = [];

    private bool $unauthorizedOnce = false;

    private bool $noAwb = false;

    private ?string $orderError = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00', 'Asia/Jakarta'));
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->bag = $this->inShop(fn () => Property::create(['name' => 'Tas Badminton', 'price_from' => 150000, 'weight_grams' => 1000]));

        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'client-api-sandbox.everpro.id/*' => fn (Request $r) => $this->everpro($r),
            'api.anthropic.com/*' => fn () => Http::response(array_shift($this->ai) ?? ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Baik, Kak.']]]),
            'gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n]),
        ]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- Fakes and helpers -------------------------------------------------------------------------------------

    private function everpro(Request $r)
    {
        $path = parse_url($r->url(), PHP_URL_PATH);
        $this->calls[] = ['method' => $r->method(), 'path' => $path, 'body' => $r->data()];
        $ok = fn ($data) => Http::response(['data' => $data, 'status' => 'success', 'status_code' => 200]);

        if ($path === '/auth/v1/token') {
            $this->assertSame(['client_key' => self::KEY, 'client_secret' => self::SECRET], $r->data());

            return $ok(['token' => 'tok-'.++$this->tokens, 'expires' => 86400, 'token_type' => 'Bearer']);
        }

        if ($this->unauthorizedOnce) {
            $this->unauthorizedOnce = false;

            return Http::response(['message' => 'token expired'], 401);
        }

        return match (true) {
            $path === '/balance/v1/detail' => $ok(['balance' => 750000]),
            $path === '/location/v1/postalcode' => $ok([
                ['sub_district_id' => 'SD-SEKELOA', 'sub_district_name' => 'Sekeloa', 'district_name' => 'Coblong', 'city_name' => 'Kota Bandung', 'province_name' => 'Jawa Barat', 'postal_code' => '40134'],
                ['sub_district_id' => 'SD-LEBAK', 'sub_district_name' => 'Lebak Gede', 'district_name' => 'Coblong', 'city_name' => 'Kota Bandung', 'province_name' => 'Jawa Barat', 'postal_code' => '40132'],
            ]),
            $path === '/shipment/v2/rates' => $ok([
                ['rate_code' => 'JNE_REG', 'logistic_name' => 'JNE', 'rate_name' => 'REG', 'shipment_price' => 18000, 'cod_fee' => ($r->data()['payment_type'] ?? null) === 'COD' ? 3000 : 0, 'min_duration' => 2, 'max_duration' => 3],
                ['rate_code' => 'SICEPAT_REG', 'logistic_name' => 'SiCepat', 'rate_name' => 'REG', 'shipment_price' => 16000, 'cod_fee' => 0],
            ]),
            $path === '/shipment/v1/pickup-times' => $ok(['available_pickup_time' => [['rate_code' => 'JNE_REG', 'pickup_time_list' => [['date' => '2026-11-05', 'pickup_times' => [['start_at' => '2026-11-05T07:00:00Z', 'end_at' => '2026-11-05T09:00:00Z']]]]]]]),
            $path === '/shipment/v2/orders' && $this->orderError !== null => Http::response(['message' => $this->orderError], 422),
            $path === '/shipment/v2/orders' => $ok(['client_order_no' => $r->data()['client_order_no'], 'order_reference_id' => 'ER-'.count($this->callsTo('/shipment/v2/orders')), 'actual_price' => 18000]),
            str_ends_with($path, '/generate') => $ok(['awb_number' => $this->noAwb ? null : 'JNE0001234']),
            str_ends_with($path, '/awb') => $ok(['awb_number' => $this->noAwb ? null : 'JNE0001234']),
            str_ends_with($path, '/label') => $ok(['url_label_pdf' => 'https://labels.example.com/JNE0001234.pdf']),
            str_ends_with($path, '/cancel') => $ok(null),
            $path === '/shipment/v1/orders/track' => $ok(array_shift($this->track) ?? []),
            str_ends_with($path, '/track') => $ok(array_shift($this->track) ?? ['awb_number' => 'JNE0001234', 'status' => 'WAITING', 'tracking_history' => []]),
            default => Http::response(['message' => 'not found'], 404),
        };
    }

    private function callsTo(string $path): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['path'] === $path));
    }

    private function inShop(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    private function configure(?Tenant $tenant = null, array $settings = []): CarrierAccount
    {
        $tenant ??= $this->shop;

        return app(CurrentTenant::class)->run($tenant, fn () => CarrierAccount::query()->create([
            'provider' => 'everpro', 'sandbox' => true,
            'credentials' => ['client_key' => self::KEY, 'client_secret' => self::SECRET, 'callback_key' => self::CALLBACK_KEY],
            'settings' => $settings + ['sender_name' => 'Quicker Sport', 'sender_phone' => '081300001111', 'sender_address' => 'Jl. Raya Kedungwaringin 1, Bekasi',
                'origin_id' => 'SD-ORIGIN', 'origin_postal' => '17540', 'service' => 'JNE REG', 'shipment_type' => 'PICKUP'],
        ]));
    }

    private function channel(AiMode $mode = AiMode::Off): WaChannel
    {
        return $this->inShop(fn () => WaChannel::query()->first() ?? WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => $mode,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function inbound(string $text, string $phone = '6281234567890'): WaMessage
    {
        $channel = $this->channel();

        return $this->inShop(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize($phone), 'text', $text, null, CarbonImmutable::now(), 'Rina',
        )));
    }

    /** A buyer who chatted, ordered two bags to Sekeloa and is in Dikemas & dikirim. */
    private function order(string $payment = 'cod', string $phone = '6281234567890', array $extra = []): Lead
    {
        $this->inbound('Halo kak, mau pesan', $phone);

        return $this->inShop(function () use ($payment, $phone, $extra) {
            $lead = Lead::query()->where('phone', PhoneNumber::normalize($phone))->firstOrFail();
            $lead->forceFill($extra + [
                'name' => 'Rina Kartika', 'property_id' => $this->bag->id, 'variant' => 'Hitam', 'quantity' => 2, 'payment_type' => $payment,
                'shipping_cost' => 10000, 'courier' => 'JNE REG',
                'shipping_address' => 'Jl. Mawar No. 5 RT 001/002, Sekeloa, Coblong, Kota Bandung 40134',
                'shipping_address_parts' => ['street' => 'Jl. Mawar', 'house' => '5', 'kelurahan' => 'Sekeloa', 'kecamatan' => 'Coblong', 'kota' => 'Kota Bandung', 'provinsi' => 'Jawa Barat', 'kode_pos' => '40134'],
                'order_items' => [['product_id' => $this->bag->id, 'name' => 'Tas Badminton', 'variant' => 'Hitam', 'quantity' => 2, 'price' => 150000]],
                'stage_id' => (new RetailStages)->stage(RetailStages::SHIPPING)->id, 'stage_entered_at' => now(),
            ])->save();

            return $lead->fresh();
        });
    }

    /** @return list<string> */
    private function sent(): array
    {
        return $this->inShop(fn () => WaMessage::query()->where('direction', 'out')->orderBy('id')->pluck('body')->all());
    }

    private function book(Lead $lead): Shipment
    {
        return $this->inShop(function () use ($lead) {
            $service = app(Shipments::class);
            [$shipment] = $service->book($lead, $service->draft($lead));

            return $shipment->fresh();
        });
    }

    private function hook(array $body, ?string $key = self::CALLBACK_KEY, ?CarrierAccount $account = null)
    {
        $account ??= CarrierAccount::query()->withoutGlobalScopes()->where('tenant_id', $this->shop->id)->firstOrFail();

        return $this->withHeaders(array_filter(['x-everpro-key' => $key]))
            ->postJson(route('webhooks.shipping', ['provider' => 'everpro', 'token' => $account->callback_token]), $body);
    }

    private function courierSays(string $code): void
    {
        $this->hook(['awb_number' => 'JNE0001234', 'client_order_no' => 'x', 'tracking_code' => $code])->assertOk();
    }

    private function shipment(): Shipment
    {
        return $this->inShop(fn () => Shipment::query()->latest('id')->firstOrFail());
    }

    // ---- Token -------------------------------------------------------------------------------------------------

    public function test_the_token_is_cached_per_agency_and_renewed_once_after_a_401(): void
    {
        Log::spy();
        $account = $this->configure();
        $carrier = Carriers::make($account);

        $this->assertSame(750000, $carrier->balance());
        $this->assertSame(750000, $carrier->balance());
        $this->assertSame(1, $this->tokens, 'One token for both calls.');

        $this->unauthorizedOnce = true;
        $this->assertSame(750000, $carrier->balance());
        $this->assertSame(2, $this->tokens, 'A 401 asks for a new token once.');

        // Expired after 24 hours (minus a margin): a new one.
        $this->travel(86400 - 200)->seconds();
        $carrier->balance();
        $this->assertSame(3, $this->tokens);

        Log::shouldNotHaveReceived('warning', fn ($m, $ctx = []) => str_contains(json_encode($ctx), self::SECRET) || str_contains(json_encode($ctx), 'tok-'));
        $this->assertStringNotContainsString(self::SECRET, (string) DB::table('carrier_accounts')->value('credentials'), 'Stored encrypted.');
    }

    // ---- Preview and booking -----------------------------------------------------------------------------------

    public function test_the_preview_prices_cod_with_the_shipping_charged_to_the_buyer(): void
    {
        $this->configure();
        $lead = $this->order('cod');

        $draft = $this->inShop(fn () => app(Shipments::class)->draft($lead));

        $this->assertTrue($draft->ok(), implode(' ', $draft->problems));
        $this->assertSame('SD-SEKELOA', $draft->destination->id, 'Mapped from the postal code and kelurahan.');
        $this->assertSame('JNE REG', $draft->rate->name());
        $this->assertSame(2000, $draft->weightGrams, '1000 g per bag, two bags.');
        $this->assertSame(300000, $draft->itemValue);
        $this->assertSame(10000, $draft->charged, 'The promo shipping the buyer was charged.');
        $this->assertSame(300000 + 10000 + 3000, $draft->codAmount, 'Items + shipping charged + Everpro COD fee.');
        $this->assertStringContainsString('Ongkir Everpro: Rp18.000 · ditagih ke pembeli: Rp10.000', $draft->summary());
        $rates = $this->callsTo('/shipment/v2/rates')[0]['body'];
        $this->assertSame('COD', $rates['payment_type']);
        $this->assertSame(['id' => 'SD-ORIGIN'], $rates['origin']);

        // Transfer: nothing to collect.
        $this->inShop(fn () => $lead->forceFill(['payment_type' => 'transfer'])->save());
        $transfer = $this->inShop(fn () => app(Shipments::class)->draft($lead->fresh()));
        $this->assertFalse($transfer->cod);
        $this->assertSame(0, $transfer->codAmount);

        // An unclear address: the agent picks.
        $this->inShop(fn () => $lead->forceFill(['shipping_address_parts' => ['kecamatan' => 'Coblong', 'kota' => 'Kota Bandung', 'kode_pos' => '40134']])->save());
        $unclear = $this->inShop(fn () => app(Shipments::class)->draft($lead->fresh()));
        $this->assertNull($unclear->destination);
        $this->assertCount(2, $unclear->candidates);
        $this->assertTrue($this->inShop(fn () => app(Shipments::class)->draft($lead->fresh(), ['location_id' => 'SD-LEBAK']))->ok());

        // Missing data is named.
        $this->inShop(fn () => $lead->forceFill(['shipping_address' => 'Bandung'])->save());
        $this->assertContains('Alamat kirim lengkap belum diisi.', $this->inShop(fn () => app(Shipments::class)->draft($lead->fresh()))->problems);
    }

    public function test_buat_resi_books_once_and_hands_the_tracking_number_to_the_existing_flow(): void
    {
        $this->configure();
        $lead = $this->order('cod');
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->callAction('createShipment')->assertHasNoActionErrors();
        // A second click (or a retry) finds the parcel already booked.
        $this->inShop(fn () => app(Shipments::class)->book($lead, app(Shipments::class)->draft($lead)));

        $this->assertCount(1, $this->callsTo('/shipment/v2/orders'));
        $order = $this->callsTo('/shipment/v2/orders')[0]['body'];
        $this->assertSame('JL-'.$this->shop->id.'-'.$lead->id.'-1', $order['client_order_no']);
        $this->assertTrue($order['is_cod']);
        $this->assertSame(313000, $order['cod_price']);
        $this->assertSame('081234567890', $order['receiver_phone'], 'Local number on the label.');
        $this->assertSame('SD-SEKELOA', $order['receiver_sub_district_id']);

        $shipment = $this->shipment();
        $this->assertSame(Shipment::SHIPPED, $shipment->state);
        $this->assertSame('JNE0001234', $shipment->awb);
        $this->assertSame('ER-1', $shipment->order_id);
        $this->assertSame('https://labels.example.com/JNE0001234.pdf', $shipment->label_url);

        $lead = $this->inShop(fn () => $lead->fresh('stage'));
        $this->assertSame('JNE0001234', $lead->tracking_number);
        $this->assertSame('JNE REG', $lead->courier);
        $resi = array_values(array_filter($this->sent(), fn ($b) => str_contains((string) $b, 'Nomor resi: JNE0001234')));
        $this->assertCount(1, $resi, 'The existing tracking message, once.');

        // The AWB callback for the same parcel changes nothing.
        $this->hook(['awb_number' => 'JNE0001234', 'client_order_no' => $shipment->client_order_no])->assertOk();
        $this->assertCount(1, array_filter($this->sent(), fn ($b) => str_contains((string) $b, 'Nomor resi: JNE0001234')));

        // Label and cancel (before pickup only).
        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->assertActionVisible('shipmentLabel')->callAction('cancelShipment');
        $this->assertSame(Shipment::CANCELLED, $this->shipment()->state);
        $this->assertCount(1, array_filter($this->calls, fn ($c) => str_ends_with($c['path'], $shipment->client_order_no.'/cancel')));

        // A new parcel after cancelling gets the next number.
        $this->book($lead);
        $this->assertSame('JL-'.$this->shop->id.'-'.$lead->id.'-2', $this->shipment()->client_order_no);
    }

    public function test_an_awb_that_comes_later_by_callback_is_handled_the_same_way(): void
    {
        $this->configure();
        $lead = $this->order('transfer');
        $this->noAwb = true;

        $shipment = $this->book($lead);
        $this->assertSame(Shipment::ORDERED, $shipment->state);
        $this->assertNull($this->inShop(fn () => $lead->fresh()->tracking_number));

        $this->hook(['awb_number' => 'JNE0009999', 'client_order_no' => $shipment->client_order_no])->assertOk();
        $this->hook(['awb_number' => 'JNE0009999', 'client_order_no' => $shipment->client_order_no])->assertOk();

        $this->assertSame('JNE0009999', $this->shipment()->awb);
        $this->assertSame('JNE0009999', $this->inShop(fn () => $lead->fresh()->tracking_number));
        $this->assertCount(1, array_filter($this->sent(), fn ($b) => str_contains((string) $b, 'Nomor resi: JNE0009999')), 'Sent once.');
    }

    public function test_a_shortage_of_balance_is_told_to_the_agent_and_not_retried(): void
    {
        $this->configure();
        $lead = $this->order();
        $this->orderError = 'Saldo tidak mencukupi';

        $shipment = $this->book($lead);

        $this->assertSame(Shipment::FAILED, $shipment->state);
        $this->assertStringContainsString('Saldo Everpro tidak cukup', $shipment->error);
        $this->assertTrue($this->inShop(fn () => $lead->activities()->where('body', 'like', '%Saldo Everpro tidak cukup%')->exists()));
    }

    // ---- Callbacks ---------------------------------------------------------------------------------------------

    public function test_callbacks_need_the_agencys_own_key_and_url(): void
    {
        $this->configure();
        $other = app(ProvisionTenant::class)('Toko Lain', 'toko-lain', User::factory()->create(), 'retail');
        $otherAccount = $this->configure($other);
        $this->inShop(fn () => CarrierAccount::query()->first()->forceFill(['credentials' => ['client_key' => self::KEY, 'client_secret' => self::SECRET, 'callback_key' => 'shop-key']])->save());
        $this->book($this->order());

        $this->hook(['awb_number' => 'JNE0001234', 'tracking_code' => 'DELIVERED'], 'wrong')->assertUnauthorized();
        $this->hook(['awb_number' => 'JNE0001234', 'tracking_code' => 'DELIVERED'], null)->assertUnauthorized();
        // The other agency's URL with its own key cannot reach this agency's parcel.
        $this->hook(['awb_number' => 'JNE0001234', 'tracking_code' => 'DELIVERED'], self::CALLBACK_KEY, $otherAccount)->assertOk();

        $this->assertSame(ShipmentStatus::AWB_CREATED, $this->shipment()->status);
        $this->hook(['awb_number' => 'JNE0001234', 'tracking_code' => 'PICKED_UP'], 'shop-key')->assertOk();
        $this->assertSame(ShipmentStatus::PICKED_UP, $this->shipment()->status);
    }

    public function test_the_buyer_hears_each_status_once_in_order_and_delivered_closes_the_lead(): void
    {
        $this->configure();
        $this->book($lead = $this->order('cod'));
        $before = count($this->sent());

        $this->courierSays('302');
        $this->courierSays('PICKED_UP');          // twice: once only
        $this->courierSays('IN_TRANSIT');         // off by default
        $this->courierSays('ONGOING_TO_RECEIVER');
        $this->courierSays('DELIVERED');
        $this->courierSays('302');                // a late, earlier status: ignored
        $this->courierSays('MYSTERY_CODE');       // unknown: only kept in the history

        $messages = array_slice($this->sent(), $before);
        $this->assertCount(3, $messages, implode("\n---\n", $messages));
        $this->assertSame('Halo kak Rina 😊 Paket Tas Badminton Hitam kakak sudah diambil kurir JNE REG dan dalam perjalanan. Resi: JNE0001234. Lacak: https://www.jne.co.id/tracking-package?awb=JNE0001234', $messages[0]);
        $this->assertStringContainsString('Mohon siapkan uang *Rp313.000*', $messages[1]);
        $this->assertSame('Paket sudah diterima ya kak Rina 😊 Terima kasih sudah belanja di Quicker Sport.', $messages[2]);
        $this->assertSame(ShipmentStatus::DELIVERED, $this->shipment()->status);
        $this->assertSame('Selesai', $this->inShop(fn () => $lead->fresh('stage')->stage->name));
        $this->assertTrue(collect($this->shipment()->events)->contains(fn ($e) => $e['code'] === 'MYSTERY_CODE'));

        // Shown as a status message in the Inbox, with no number introduction.
        $out = $this->inShop(fn () => WaMessage::query()->where('origin', 'shipping')->get());
        $this->assertCount(3, $out);
        $this->assertFalse($out->contains('is_intro', true));
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        Livewire::test(Inbox::class, ['leadId' => $lead->id])->assertSee('· Status kiriman');
    }

    public function test_transfer_orders_get_the_text_without_money(): void
    {
        $this->configure();
        $this->book($this->order('transfer'));
        $before = count($this->sent());

        $this->courierSays('ONGOING_TO_RECEIVER');

        $this->assertSame('Kak Rina, paket Tas Badminton Hitam sedang diantar kurir hari ini 🛵 Pastikan ada yang menerima ya kak 🙏', array_slice($this->sent(), $before)[0]);
    }

    public function test_quiet_hours_hold_messages_and_a_stale_out_for_delivery_is_dropped(): void
    {
        $this->configure();
        $this->book($this->order());
        $before = count($this->sent());

        Carbon::setTestNow(Carbon::parse('2026-11-05 22:30', 'Asia/Jakarta'));
        $this->courierSays('PICKED_UP');
        $this->courierSays('ONGOING_TO_RECEIVER');
        $this->assertCount($before, $this->sent(), 'Nothing at night: '.implode(' | ', $this->sent()));
        $this->assertSame(2, $this->inShop(fn () => ShipmentNotice::query()->where('state', 'pending')->count()));

        Carbon::setTestNow(Carbon::parse('2026-11-06 05:01', 'Asia/Jakarta'));
        $this->artisan('shipping:notices')->assertSuccessful();

        $messages = array_slice($this->sent(), $before);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('sudah diambil kurir', $messages[0]);
        $this->assertSame('Hari pengantaran sudah lewat', $this->inShop(fn () => ShipmentNotice::query()->where('status', 'out_for_delivery')->value('note')));
    }

    public function test_a_failed_delivery_tells_the_owner_and_a_return_never_reaches_the_buyer(): void
    {
        $this->configure();
        $this->book($lead = $this->order());
        $before = count($this->sent());

        $this->hook(['awb_number' => 'JNE0001234', 'tracking_code' => 'FAILED', 'reason' => 'Rumah kosong'])->assertOk();

        $messages = array_slice($this->sent(), $before);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('kurir belum berhasil mengantar paket kakak (Rumah kosong)', $messages[0]);
        Mail::assertSent(ShipmentNeedsAction::class);
        $admin = $this->adminOf($this->shop);
        $this->assertSame(1, $admin->notifications()->count());
        $this->actingInTenant($admin, $this->shop);
        Livewire::test(Inbox::class, ['leadId' => $lead->id])->assertSee('Perlu tindakan');

        $this->courierSays('IN_PROCESS_RETURN');
        $this->courierSays('RETURNED');

        $this->assertCount(1, array_slice($this->sent(), $before), 'Returns are never sent to the buyer.');
        $this->assertSame(ShipmentStatus::RETURNED, $this->shipment()->status);
        $this->assertSame('Dikemas & dikirim', $this->inShop(fn () => $lead->fresh('stage')->stage->name), 'Not cancelled automatically.');
        $this->assertTrue($this->inShop(fn () => $lead->activities()->where('body', 'like', 'Paket sudah kembali ke toko%')->exists()));
        $this->assertSame(3, $admin->notifications()->count());
    }

    // ---- Scheduled tracking --------------------------------------------------------------------------------------

    public function test_the_two_hourly_tracking_catches_a_missed_callback(): void
    {
        $this->configure();
        $this->book($lead = $this->order());
        $before = count($this->sent());
        $this->track[] = [['awb_number' => 'JNE0001234', 'status' => 'DELIVERED', 'tracking_history' => [
            ['date' => '2026-11-05T02:00:00Z', 'status' => 'PICKED_UP', 'description' => 'Paket diambil kurir'],
            ['date' => '2026-11-05T02:30:00Z', 'status' => 'DELIVERED', 'description' => 'Diterima oleh RINA'],
        ]]];

        $this->artisan('shipping:track')->assertSuccessful();

        $this->assertSame(ShipmentStatus::DELIVERED, $this->shipment()->status);
        $this->assertSame(['Paket sudah diterima ya kak Rina 😊 Terima kasih sudah belanja di Quicker Sport.'], array_slice($this->sent(), $before));
        $this->assertSame('Selesai', $this->inShop(fn () => $lead->fresh('stage')->stage->name));

        // Finished parcels are not tracked again.
        $calls = count($this->callsTo('/shipment/v1/orders/track'));
        $this->artisan('shipping:track')->assertSuccessful();
        $this->assertCount($calls, $this->callsTo('/shipment/v1/orders/track'));
    }

    // ---- Bulk ----------------------------------------------------------------------------------------------------

    public function test_bulk_buat_resi_and_labels_on_the_lead_list(): void
    {
        $this->configure();
        $a = $this->order('cod', '6281234567890');
        $b = $this->order('transfer', '6281234567891');
        $c = $this->order('transfer', '6281234567892', ['shipping_address' => 'Bdg']);
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(ListLeads::class)->callTableBulkAction('bulkCreateShipments', [$a, $b, $c]);

        $this->assertSame(2, $this->inShop(fn () => Shipment::query()->count()));
        $this->assertNull($this->inShop(fn () => Shipment::query()->where('lead_id', $c->id)->first()));

        Livewire::test(ListLeads::class)->callTableBulkAction('bulkShipmentLabels', [$a, $b])->assertNotified('2 label siap dicetak');
    }

    // ---- AI ------------------------------------------------------------------------------------------------------

    public function test_the_ai_answers_where_the_parcel_is_from_the_stored_status_or_hands_over(): void
    {
        $this->configure();
        $this->channel(AiMode::Auto);
        $this->book($lead = $this->order());
        $this->courierSays('ONGOING_TO_RECEIVER');
        $this->ai[] = ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Paket kakak sedang diantar kurir JNE REG hari ini, resi JNE0001234 ya Kak.']]];

        $this->inbound('Kak paketnya sampai mana ya?');

        $request = collect(Http::recorded())->map(fn ($pair) => $pair[0])->last(fn (Request $r) => str_contains($r->url(), 'anthropic'));
        $this->assertStringContainsString('Status terakhir: Sedang diantar', json_encode($request->data()['system'] ?? $request->data(), JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Paket kakak sedang diantar', (string) last($this->sent()));

        // An invented estimate goes to a person.
        $this->ai[] = ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Paket kakak sampai 2 hari lagi ya Kak.']]];
        $this->inbound('Kapan sampai kak?');
        $this->assertSame(AiDraft::HANDOFF, $this->inShop(fn () => AiDraft::query()->latest('id')->value('status')));

        // A stored status older than 2 hours is tracked again first.
        $tracks = count(array_filter($this->calls, fn ($c) => str_ends_with($c['path'], '/JNE0001234/track')));
        $this->travel(3)->hours();
        $this->inbound('Resinya berapa kak?');
        $this->assertCount($tracks + 1, array_filter($this->calls, fn ($c) => str_ends_with($c['path'], '/JNE0001234/track')));
    }

    public function test_the_ai_hands_over_a_parcel_question_without_any_parcel_data(): void
    {
        $this->channel(AiMode::Auto);
        $this->inbound('Halo kak');
        $anthropic = Http::recorded()->filter(fn ($p) => str_contains($p[0]->url(), 'anthropic'))->count();

        $this->inbound('Paket saya sudah sampai mana?');

        $this->assertSame(AiDraft::HANDOFF, $this->inShop(fn () => AiDraft::query()->latest('id')->value('status')));
        $this->assertSame($anthropic, Http::recorded()->filter(fn ($p) => str_contains($p[0]->url(), 'anthropic'))->count(), 'No model call.');
    }

    // ---- Hidden without a provider, settings ------------------------------------------------------------------

    public function test_menus_are_hidden_and_nothing_calls_out_without_a_provider(): void
    {
        $lead = $this->order();
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->assertActionHidden('createShipment');
        Livewire::test(Inbox::class, ['leadId' => $lead->id])->assertActionHidden('createShipment')->assertDontSee('Buat resi');
        Livewire::test(ListLeads::class)->assertTableBulkActionHidden('bulkCreateShipments');
        $this->artisan('shipping:track')->assertSuccessful();

        $this->assertSame([], $this->calls);
    }

    public function test_the_settings_keep_credentials_secret_and_show_the_callback_url(): void
    {
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(ShippingSettingsPage::class)
            ->fillForm(['provider' => 'everpro', 'sandbox' => true, 'client_key' => self::KEY, 'client_secret' => self::SECRET, 'callback_key' => self::CALLBACK_KEY,
                'sender_name' => 'Quicker Sport', 'sender_phone' => '081300001111', 'sender_address' => 'Jl. Raya Kedungwaringin 1, Bekasi'], 'carrierForm')
            ->call('saveCarrier')->assertHasNoFormErrors([], 'carrierForm');

        $account = $this->inShop(fn () => CarrierAccount::query()->firstOrFail());
        $this->assertSame(self::SECRET, $account->credential('client_secret'));

        $page = Livewire::test(ShippingSettingsPage::class)
            ->assertDontSee(self::SECRET)->assertDontSee(self::KEY)->assertSee('Tersimpan (ketik untuk mengganti)')
            ->assertSee(route('webhooks.shipping', ['provider' => 'everpro', 'token' => $account->callback_token]))
            ->assertSee('Pesan status kiriman');

        // Saving again without typing keeps the stored keys.
        $page->call('saveCarrier');
        $this->assertSame(self::KEY, $this->inShop(fn () => CarrierAccount::query()->firstOrFail())->credential('client_key'));

        $page->call('testConnection')->assertNotified('Koneksi berhasil');
        $this->assertSame(750000, $this->inShop(fn () => CarrierAccount::query()->firstOrFail())->balance);

        // Messages: switched off and edited per status.
        $page->fillForm(['picked_up' => ['enabled' => false, 'text' => 'x'], 'delivered' => ['enabled' => true, 'text' => 'Terima kasih {nama} {kupon}']], 'noticesForm')
            ->assertSee('Variabel tidak dikenal: {kupon}')->call('saveNotices');
        $messages = $this->inShop(fn () => CarrierAccount::query()->firstOrFail())->messages;
        $this->assertFalse($messages['picked_up']['enabled']);
        $this->assertSame('Terima kasih {nama} {kupon}', $messages['delivered']['text']);

        // An unknown variable never goes out raw.
        $this->inShop(fn () => CarrierAccount::query()->firstOrFail()->forceFill(['settings' => ['sender_name' => 'Q', 'sender_phone' => '081300001111', 'sender_address' => 'Jl. Raya Kedungwaringin 1', 'origin_id' => 'SD-ORIGIN']])->save());
        $this->book($this->order());
        $before = count($this->sent());
        $this->courierSays('DELIVERED');
        $this->assertSame(['Terima kasih Rina -'], array_slice($this->sent(), $before));
    }
}
