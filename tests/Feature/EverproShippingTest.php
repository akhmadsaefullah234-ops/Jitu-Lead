<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\MoveLeadToStage;
use App\Actions\ProvisionTenant;
use App\Automation\RetailStages;
use App\Enums\AiMode;
use App\Enums\Role;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\ImportResi;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\ShippingSettingsPage;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Filament\Support\ShipmentTimeline;
use App\Jobs\FetchShipmentAwb;
use App\Leads\AddressCheck;
use App\Leads\ChatShippingDetails;
use App\Mail\ShipmentNeedsAction;
use App\Mail\ShipmentWaitsForBalance;
use App\Models\AiDraft;
use App\Models\CarrierAccount;
use App\Models\LabelBatch;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Shipment;
use App\Models\ShipmentNotice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Reports\DailyReport;
use App\Shipping\Carriers\CarrierException;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Carriers\EverproCarrier;
use App\Shipping\Carriers\ShipmentStatus;
use App\Shipping\Shipments\AutoShipments;
use App\Shipping\Shipments\LabelSheet;
use App\Shipping\Shipments\LabelStore;
use App\Shipping\Shipments\ResiImport;
use App\Shipping\Shipments\Shipments;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\Support\SpreadsheetReader;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
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

    /**
     * Everpro's own order list (GET /shipment/v1/orders), newest first. A prepaid booking lands here under
     * Everpro's own TRD… number, whatever client_order_no was sent; "awb_number" null = follows $noAwb.
     */
    private array $remoteOrders = [];

    /** Everpro refuses /generate (once per value set). */
    private ?string $generateError = null;

    private int $generateStatus = 422;

    /** GET .../awb answers 404 "awb number not found" (no tracking number was made). */
    private bool $awbMissing = false;

    /** Balances GET /balance/v1/detail answers in turn before falling back to $balanceAnswer. */
    private array $balances = [];

    /** The /v2/orders answer is "data": null. */
    private bool $orderDataNull = false;

    private ?string $orderError = null;

    private bool $locationDown = false;

    /** The provider has no label PDF yet. */
    private bool $labelsDown = false;

    /** Tracking numbers Everpro does not know (made outside this account). */
    private array $unknownAwbs = [];

    /** POST /orders/track answers per number (when set), and numbers whose batch Everpro refuses with a 400. */
    private array $trackByAwb = [];

    private array $rejectAwbs = [];

    /** The documented answer of GET /balance/v1/detail. */
    private array $balanceAnswer = ['cash' => 750000, 'name' => 'Quicker Sport'];

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
            'labels.example.com/*' => fn (Request $r) => $this->labelFile($r),
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
        $this->calls[] = ['method' => $r->method(), 'path' => $path, 'body' => $r->data(), 'raw' => $r->body()];
        $ok = fn ($data) => Http::response(['data' => $data, 'status' => 'success', 'status_code' => 200]);

        if ($path === '/auth/v1/token') {
            $this->assertSame(['client_key' => self::KEY, 'client_secret' => self::SECRET], $r->data());

            return $ok(['token' => 'tok-'.++$this->tokens, 'expires' => 86400, 'token_type' => 'Bearer']);
        }

        if ($this->unauthorizedOnce) {
            $this->unauthorizedOnce = false;

            return Http::response(['message' => 'token expired'], 401);
        }

        if ($path === '/shipment/v1/orders/track') {
            $asked = (array) ($r->data()['awb_numbers'] ?? []);

            // Everpro's real answer to a number sent as a JSON number.
            if (collect($asked)->contains(fn ($a) => ! is_string($a))) {
                return Http::response(['message' => 'Unmarshal type error: expected=string, got=number, field=awb_numbers, offset=31', 'data' => null], 400);
            }

            if (array_intersect($asked, $this->rejectAwbs) !== []) {
                return Http::response(['message' => 'invalid awb number', 'data' => null], 400);
            }

            if ($this->trackByAwb !== []) {
                return $ok(array_values(array_filter(array_map(fn ($a) => $this->trackByAwb[$a] ?? null, $asked))));
            }
        }

        if (str_ends_with($path, '/track') && in_array(basename(dirname($path)), $this->unknownAwbs, true)) {
            return Http::response(['message' => 'order not found', 'data' => null], 404);
        }

        return match (true) {
            $path === '/balance/v1/detail' => $ok($this->balances !== [] ? ['cash' => array_shift($this->balances), 'name' => 'Quicker Sport'] : $this->balanceAnswer),
            $path === '/location/v1/postalcode' => $this->postalCode($r),
            $path === '/location/v1/sub-districts' && isset($r->data()['district_id']) => $r->data()['district_id'] === 'D-KDW'
                ? $ok([['sub_district_id' => 'SD-KDW', 'sub_district_name' => 'Kedungwaringin', 'postal_code' => '17540'], ['sub_district_id' => 'SD-KDJ', 'sub_district_name' => 'Karangsambung', 'postal_code' => '17540']])
                : Http::response(['message' => 'not found', 'data' => []], 404),
            $path === '/location/v1/sub-districts' => strtolower($r->data()['sub_district_name'] ?? '') === 'sekeloa'
                ? $ok([['sub_district_id' => 'SD-SEKELOA', 'sub_district_name' => 'Sekeloa', 'postal_code' => '40134']])
                : Http::response(['message' => 'not found', 'data' => []], 404),
            $path === '/location/v1/districts' => strtolower($r->data()['district_name'] ?? '') === 'kedungwaringin'
                ? $ok([['district_id' => 'D-KDW', 'district_name' => 'Kedungwaringin']])
                : Http::response(['message' => 'not found', 'data' => []], 404),
            $path === '/shipment/v2/rates' => $ok(self::ratesFixture(($r->data()['is_cod'] ?? false) === true)),
            $path === '/shipment/v1/pickup-times' => $ok(['available_pickup_time' => [['rate_code' => 'REG23', 'pickup_time_list' => [['date' => '2026-11-05', 'pickup_times' => [['start_at' => '2026-11-05T07:00:00Z', 'end_at' => '2026-11-05T09:00:00Z']]]]]]]),
            $path === '/shipment/v2/orders' && $this->orderError !== null => Http::response(['message' => $this->orderError], 422),
            $path === '/shipment/v2/orders' && $this->orderDataNull => $this->listed($r, $ok(null)),
            str_ends_with($path, '/generate') && $this->generateError !== null => Http::response(['message' => $this->generateError], $this->generateStatus),
            str_ends_with($path, '/awb') && $this->awbMissing => Http::response(['message' => 'awb number not found', 'data' => null], 404),
            $path === '/shipment/v1/orders' => $ok(['orders' => array_map(fn ($o) => ['awb_number' => $o['awb_number'] ?? ($this->noAwb ? '' : $o['_awb'] ?? 'JNE0001234')] + $o, $this->remoteOrders),
                'pagination' => ['page' => 1, 'limit' => 50, 'total_page' => 1, 'total_data' => count($this->remoteOrders)]]),
            $path === '/shipment/v2/orders' => $this->listed($r, null) ?? $ok(['client_order_no' => $r->data()['client_order_no'], 'order_reference_id' => 'ER-'.count($this->callsTo('/shipment/v2/orders')), 'actual_price' => 18000]),
            str_ends_with($path, '/generate') => $ok(['awb_number' => $this->noAwb ? null : (collect($this->remoteOrders)->firstWhere('shipment_order_no', basename(dirname($path)))['_awb'] ?? 'JNE0001234')]),            str_ends_with($path, '/awb') => $ok(['awb_number' => $this->noAwb ? null : 'JNE0001234', 'order_reference_id' => 'ER-LATER']),
            str_ends_with($path, '/label') => $ok(['url_label_pdf' => 'https://labels.example.com/'.basename(dirname($path)).'.pdf']),
            str_ends_with($path, '/cancel') => $ok(null),
            $path === '/shipment/v1/orders/track' => $ok(array_shift($this->track) ?? []),
            str_ends_with($path, '/track') => $ok(array_shift($this->track) ?? ['awb_number' => 'JNE0001234', 'status' => 'WAITING', 'tracking_history' => []]),
            default => Http::response(['message' => 'not found'], 404),
        };
    }

    /** Puts a booking into Everpro's order list, as Everpro numbers it, and answers $answer. */
    private function listed(Request $r, $answer)
    {
        $body = $r->data();
        $n = count($this->remoteOrders) + 1;
        array_unshift($this->remoteOrders, [
            '_awb' => $n === 1 ? 'JNE0001234' : 'JNE000'.(1233 + $n),
            'shipment_order_no' => 'TRD20261105'.str_pad((string) (count($this->remoteOrders) + 1), 5, '0', STR_PAD_LEFT),
            'client_order_no' => 'TRD20261105'.str_pad((string) (count($this->remoteOrders) + 1), 5, '0', STR_PAD_LEFT),
            'order_reference_id' => '', 'awb_number' => null, 'status_id' => 100, 'status' => 'ORDER CREATED',
            'order_date' => now('Asia/Jakarta')->format('Y-m-d H:i:s'),
            'receiver_name' => $body['receiver_name'] ?? '', 'receiver_phone_number' => $body['receiver_phone'] ?? '',
            'package_desc' => $body['package_desc'] ?? '', 'package_price' => $body['package_price'] ?? 0,
            'courier_detail' => 'JNE Reguler', 'is_cod' => (bool) ($body['is_cod'] ?? false), 'total_cod' => ($body['is_cod'] ?? false) ? (int) $body['cod_price'] : 0,
            'shipment_price' => 18000, 'cod_fee' => ($body['is_cod'] ?? false) ? 4000 : 0, 'total_cod_disbursement' => ($body['is_cod'] ?? false) ? (int) $body['cod_price'] - 22000 : 0,
            'cashback' => 2000, 'label_url' => '',
        ]);

        return $answer;
    }

    /** A label PDF of its own size per tracking number (width from the digits), a server error for AWB-DOWN, a web page for AWB-HTML. */
    private function labelFile(Request $r)
    {
        $awb = basename((string) parse_url($r->url(), PHP_URL_PATH), '.pdf');

        if ($this->labelsDown) {
            return Http::response('not ready', 404);
        }

        return match ($awb) {
            'AWB-DOWN' => Http::response('error', 500),
            'AWB-HTML' => Http::response('<html>login</html>', 200, ['Content-Type' => 'text/html']),
            default => Http::response(self::pdf(min(2000, (int) preg_replace('/\D/', '', $awb)) ?: 100), 200, ['Content-Type' => 'application/pdf']),
        };
    }

    /** A small one-page PDF, $width × 150 mm. */
    private static function pdf(int $width): string
    {
        $pdf = new \FPDF('P', 'mm', [$width, 150]);
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 10, 'Label');

        return $pdf->Output('S');
    }

    /** Like production: only a 5-digit postal code. */
    private function postalCode(Request $r)
    {
        if ($this->locationDown) {
            return Http::response(['message' => 'internal error'], 500);
        }

        $code = (string) ($r->data()['postal_code'] ?? '');

        if (! preg_match('/^\d{5}$/', $code)) {
            return Http::response(['message' => 'PostalCode must be 5 characters in length', 'data' => null], 400);
        }

        $place = fn ($id, $village, $district, $city, $province) => ['sub_district_id' => $id, 'sub_district_name' => $village, 'district_name' => $district,
            'city_name' => $city, 'province_name' => $province, 'postal_code' => $code];

        return match ($code) {
            '40134' => Http::response(['data' => [$place('SD-SEKELOA', 'Sekeloa', 'Coblong', 'Kota Bandung', 'Jawa Barat'), $place('SD-LEBAK', 'Lebak Gede', 'Coblong', 'Kota Bandung', 'Jawa Barat')]]),
            '17540' => Http::response(['data' => [$place('SD-KDW', 'Kedungwaringin', 'Kedungwaringin', 'Kab. Bekasi', 'Jawa Barat'), $place('SD-KDJ', 'Karangsambung', 'Kedungwaringin', 'Kab. Bekasi', 'Jawa Barat')]]),
            default => Http::response(['message' => 'not found', 'data' => []], 404),
        };
    }

    /**
     * A /shipment/v2/rates answer (inside "data") in the shape production gives: grouped by kind of service,
     * logistic_code always empty, numbers sometimes strings, no COD fee. With is_cod some services drop out.
     */
    private static function ratesFixture(bool $cod): array
    {
        $rate = fn (string $logistic, string $code, string $name, int|string $price, int|string $after, int $cashback, int $min, int $max, string $key, array $extra = []) => $extra + [
            'signed_key' => $key, 'name' => 'Everpro', 'logistic_name' => $logistic, 'logistic_logo_url' => 'https://cdn.example.com/'.strtolower($logistic).'.png',
            'rate_code' => $code, 'rate_name' => $name, 'min_duration' => $min, 'max_duration' => $max, 'duration_type' => 'DAY',
            'price' => $price, 'shipment_price_after_discount' => $after, 'surcharge' => 0, 'surcharge_details' => ['commodity_surcharge' => 0, 'woodpacking_surcharge' => 0],
            'insurance_price' => 1500, 'weight' => 2, 'volume_weight' => 0, 'is_available_pickup_today' => true, 'cashback' => $cashback,
            'is_flat_rate' => false, 'is_use_wood_packing' => false, 'logistic_code' => '',
        ];

        return [
            'regular' => array_values(array_filter([
                $rate('SiCepat', 'REG', 'Regular', 16000, '16000', 0, 1, 2, 'sig-sicepat-reg', ['is_flat_rate' => true, 'insurance_price' => 0]),
                $rate('JNE', 'REG23', 'Regular', '18000', 18000, 500, 2, 3, 'sig-jne-reg'),
                $rate('J&T Express', 'EZ', 'Regular', 19000, 19000, 900, 2, 3, 'sig-jnt-ez'),
                $cod ? null : $rate('Lion Parcel', 'BOSSPACK', 'BOSSPACK', 15000, 14000, 0, 2, 4, 'sig-lion-boss'),
            ])),
            'express' => [$rate('JNE', 'YES23', 'Yakin Esok Sampai', 32000, 32000, 0, 1, 1, 'sig-jne-yes')],
            'same_day' => [],
            'instant' => [],
            'cargo' => [$rate('JNE', 'JTR23', 'Trucking', 45000, 40000, 0, 3, 7, 'sig-jne-jtr', ['surcharge' => '2500'])],
            'vehicle' => [],
        ];
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

    public function test_the_balance_is_read_from_data_cash_and_unknown_answers_log_only_their_keys(): void
    {
        Log::spy();
        $carrier = Carriers::make($this->configure());

        $this->assertSame(750000, $carrier->balance());

        $this->balanceAnswer = ['balance' => 5000];
        $this->assertSame(5000, $carrier->balance(), 'Other names still work.');

        $this->balanceAnswer = ['wallet' => 99000, 'name' => 'Quicker Sport'];
        $this->assertNull($carrier->balance());
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $ctx) => $m === 'Everpro balance not found in the answer' && $ctx['keys'] === ['wallet', 'name']
            && ! str_contains(json_encode($ctx), '99000') && ! str_contains(json_encode($ctx), 'Quicker'))->once();
    }

    // ---- Location search ---------------------------------------------------------------------------------------

    /** @return list<string> labels of the places found */
    private function find(?string $postal, ?string $area = null): array
    {
        return array_map(fn ($l) => $l->label(), Carriers::make(CarrierAccount::query()->withoutGlobalScopes()->firstOrFail())->locations($postal, $area));
    }

    private function postalCalls(): array
    {
        return array_map(fn ($c) => $c['body']['postal_code'] ?? null, $this->callsTo('/location/v1/postalcode'));
    }

    public function test_a_postal_code_is_looked_up_directly(): void
    {
        $this->configure();

        $this->assertSame(['Sekeloa, Coblong, Kota Bandung, Jawa Barat (40134)', 'Lebak Gede, Coblong, Kota Bandung, Jawa Barat (40134)'], $this->find('40134'));
        $this->find('40134');
        $this->assertSame(['40134'], $this->postalCalls(), 'Cached after the first answer.');
    }

    public function test_a_kelurahan_name_is_found_through_everpros_list(): void
    {
        $this->configure();

        $this->assertSame(['Sekeloa, Coblong, Kota Bandung, Jawa Barat (40134)'], $this->find(null, 'Sekeloa'));
        $this->assertSame(['40134'], $this->postalCalls());
    }

    public function test_a_kecamatan_name_is_found_through_everpros_list_or_the_imported_regions(): void
    {
        $this->configure();

        $this->assertSame(['Karangsambung, Kedungwaringin, Kab. Bekasi, Jawa Barat (17540)', 'Kedungwaringin, Kedungwaringin, Kab. Bekasi, Jawa Barat (17540)'], $this->find(null, 'kedungwaringin'));

        // With the regions imported, the postal code comes from them and Everpro's list is not needed.
        $this->calls = [];
        Cache::flush();
        DB::table('regions')->insert([
            ['code' => '32', 'parent_code' => null, 'level' => 1, 'name' => 'Jawa Barat', 'search' => 'jawa barat', 'postal_code' => null],
            ['code' => '32.16', 'parent_code' => '32', 'level' => 2, 'name' => 'Kabupaten Bekasi', 'search' => 'bekasi', 'postal_code' => null],
            ['code' => '32.16.22', 'parent_code' => '32.16', 'level' => 3, 'name' => 'Kedungwaringin', 'search' => 'kedungwaringin', 'postal_code' => null],
            ['code' => '32.16.22.2001', 'parent_code' => '32.16.22', 'level' => 4, 'name' => 'Karangsambung', 'search' => 'karangsambung', 'postal_code' => '17540'],
        ]);

        $this->assertCount(2, $this->find(null, 'Kec. Kedungwaringin'));
        $this->assertSame([], array_merge($this->callsTo('/location/v1/districts'), $this->callsTo('/location/v1/sub-districts')));
        $this->assertSame(['17540'], $this->postalCalls());
    }

    public function test_text_that_matches_nothing_finds_nothing_and_postalcode_always_gets_five_digits(): void
    {
        $this->configure();

        $this->assertSame([], $this->find(null, 'antah berantah'));
        $this->assertSame([], $this->find('123'));
        $this->assertSame([], $this->find(null, 'ab'));
        $this->assertSame([], $this->postalCalls(), 'Never called without a 5-digit code.');

        // The settings search: name or postal code.
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        $options = Livewire::test(ShippingSettingsPage::class)->instance()->getSchema('carrierForm')->getFlatFields(withHidden: true)['origin_id'];
        $this->assertSame(['SD-KDJ', 'SD-KDW'], array_keys($options->getSearchResults('kedungwaringin')));
        $this->assertSame([], $options->getSearchResults('antah berantah'));
        Livewire::test(ShippingSettingsPage::class)->assertSee('Ketik kode pos (paling cepat) atau nama kelurahan/kecamatan');
    }

    public function test_an_everpro_error_is_not_cached_as_an_empty_answer(): void
    {
        $this->configure();
        $this->locationDown = true;

        try {
            $this->find('40134');
            $this->fail('The error reaches the caller.');
        } catch (CarrierException $e) {
            $this->assertStringContainsString('Everpro sedang tidak bisa memproses', $e->getMessage());
        }

        $this->locationDown = false;
        $this->assertCount(2, $this->find('40134'), 'Asked again, not an empty answer from the cache.');
    }

    public function test_buat_resi_maps_an_address_without_postal_code_by_its_names(): void
    {
        $this->configure();
        $lead = $this->order('cod', '6281234567890', ['shipping_address_parts' => ['street' => 'Jl. Mawar', 'kelurahan' => 'Sekeloa', 'kecamatan' => 'Coblong', 'kota' => 'Kota Bandung']]);

        $draft = $this->inShop(fn () => app(Shipments::class)->draft($lead));

        $this->assertTrue($draft->ok(), implode(' ', $draft->problems));
        $this->assertSame('SD-SEKELOA', $draft->destination->id);
        $this->assertNotContains(null, $this->postalCalls());
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
        $this->assertSame(300000 + 10000, $draft->codAmount, 'Items + the shipping charged; the rates answer has no COD fee.');
        $this->assertStringContainsString('Ongkir asli Everpro: Rp18.000 · cashback Everpro Rp500', $draft->summary());
        $this->assertStringContainsString('Ongkir ditagih ke pembeli: Rp10.000 (potongan/subsidi penjual Rp8.000)', $draft->summary());
        $this->assertStringContainsString('Total COD (ditagih kurir ke pembeli): Rp310.000', $draft->summary());
        $rates = $this->callsTo('/shipment/v2/rates')[0]['body'];
        $this->assertTrue($rates['is_cod']);
        $this->assertSame('17540', $rates['origin_postal_code']);
        $this->assertSame('40134', $rates['destination_postal_code']);

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

    public function test_rates_and_orders_send_the_flat_body_everpro_documents(): void
    {
        $this->configure(settings: ['origin_label' => 'Kedungwaringin, Kedungwaringin, Kab. Bekasi, Jawa Barat (17540)']);
        $lead = $this->order('cod');
        Log::spy();

        $this->book($lead);

        $rates = $this->callsTo('/shipment/v2/rates')[0]['body'];
        foreach (['origin_postal_code', 'destination_postal_code', 'package_type_id', 'weight', 'shipment_type', 'item_price'] as $key) {
            $this->assertArrayHasKey($key, $rates, "rates needs {$key}");
        }
        $this->assertSame([], array_filter($rates, 'is_array'), 'Flat: nothing nested.');
        $this->assertArrayNotHasKey('origin', $rates);
        $this->assertArrayNotHasKey('package', $rates);
        $this->assertSame('17540', $rates['origin_postal_code']);
        $this->assertSame('40134', $rates['destination_postal_code']);
        $this->assertSame('Kedungwaringin', $rates['origin_sub_district_name']);
        $this->assertSame('Sekeloa', $rates['destination_sub_district_name']);
        $this->assertSame(1, $rates['package_type_id']);
        $this->assertSame(2, $rates['weight'], '2000 g is 2 kg.');
        $this->assertSame(300000, $rates['item_price']);
        $this->assertTrue($rates['is_cod']);
        $this->assertSame(310000, $rates['cod_price'], 'Items + the shipping charged.');
        $this->assertSame('PICKUP', $rates['shipment_type']);

        $order = $this->callsTo('/shipment/v2/orders')[0]['body'];
        foreach (['max_duration', 'min_duration', 'package_desc', 'package_price', 'package_type_id', 'rate_code', 'receiver_address', 'receiver_name', 'receiver_phone',
            'receiver_postal_code', 'shipment_price', 'shipment_type', 'shipper_address', 'shipper_name', 'shipper_phone', 'shipper_postal_code', 'weight'] as $key) {
            $this->assertArrayHasKey($key, $order, "orders needs {$key}");
        }
        $this->assertSame([], array_filter($order, 'is_array'), 'Flat: nothing nested.');
        foreach (['shipper_sub_district_id', 'receiver_sub_district_id', 'package_count'] as $gone) {
            $this->assertArrayNotHasKey($gone, $order);
        }
        $this->assertSame(2, $order['weight']);
        $this->assertSame(300000, $order['package_price']);
        $this->assertSame('REG23', $order['rate_code']);
        $this->assertSame(18000, $order['shipment_price']);
        $this->assertSame([2, 3], [$order['min_duration'], $order['max_duration']]);
        $this->assertSame(['sig-jne-reg', 500, false], [$order['signed_key'], $order['cashback'], $order['is_flat_rate']], 'What the chosen rate gave back.');
        $this->assertArrayNotHasKey('rateID', $order, 'Not in the answer, so not sent.');
        $this->assertSame('17540', $order['shipper_postal_code']);
        $this->assertSame('Kedungwaringin', $order['shipper_sub_district_name']);
        $this->assertSame('Sekeloa', $order['receiver_sub_district_name']);
        $this->assertMatchesRegularExpression('/^62\d{8,13}$/', $order['receiver_phone']);
        $this->assertSame('6281300001111', $order['shipper_phone']);
        $this->assertSame('2026-11-05T07:00:00+00:00', $order['pickup_time']);

        // Only the field names of the answer are logged, once.
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => $m === 'Everpro rates answer keys' && in_array('signed_key', $c['rate'], true)
            && $c['groups']['regular'] === 3 && ! str_contains(json_encode($c), 'sig-'))->once();

        $this->assertSame(1, EverproCarrier::kg(1000));
        $this->assertSame(1.5, EverproCarrier::kg(1500));
        $this->assertSame(0.25, EverproCarrier::kg(250));
    }

    public function test_ambil_dari_chat_fills_the_buat_resi_form_from_the_conversation(): void
    {
        $this->configure();
        $lead = $this->order('cod', extra: ['shipping_address' => null, 'shipping_address_parts' => null]);
        // Written in the chat before details were read from it.
        $this->inShop(fn () => $lead->conversation()->first()->messages()->create([
            'channel_id' => $this->channel()->id, 'direction' => 'in', 'type' => 'text', 'status' => 'delivered', 'sent_at' => now(),
            'body' => "Nama: Rina Kartika\nAlamat: Jl. Mawar No. 5 RT 1/2, Kel. Sekeloa, Kec. Coblong, Kota Bandung 40134",
        ]));
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        $page = Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->mountAction('createShipment');
        $page->callAction(TestAction::make('fromChat')->schemaComponent('addressActions', 'mountedActionSchema0'))
            ->assertSchemaStateSet(['addr_text' => 'Jl. Mawar No. 5 RT 1/2, Kel. Sekeloa, Kec. Coblong, Kota Bandung 40134', 'addr_kelurahan' => 'Sekeloa', 'addr_kode_pos' => '40134'], 'mountedActionSchema0')
            ->assertNotified();
        $this->assertNull($this->inShop(fn () => $lead->fresh()->shipping_address), 'Nothing saved until the resi is made.');

        // The agent corrects the landmark, then books.
        $page->setActionData(['addr_landmark' => 'Pagar hijau'])->callMountedAction()->assertHasNoActionErrors();

        $lead = $this->inShop(fn () => $lead->fresh());
        $this->assertSame('Jl. Mawar No. 5 RT 1/2, Kel. Sekeloa, Kec. Coblong, Kota Bandung 40134', $lead->shipping_address);
        $this->assertSame('Pagar hijau', $lead->shipping_address_parts['landmark']);
        $order = $this->callsTo('/shipment/v2/orders')[0]['body'];
        $this->assertSame('40134', $order['receiver_postal_code']);
        $this->assertSame('Pagar hijau', $order['receiver_address_note']);
    }

    public function test_a_product_without_a_price_is_named_before_anything_is_sent(): void
    {
        $this->configure();
        $lead = $this->order('cod', extra: ['order_items' => [['product_id' => $this->bag->id, 'name' => 'Tas Badminton', 'quantity' => 1, 'price' => 0]]]);
        $this->inShop(fn () => $this->bag->forceFill(['price_from' => null])->save());

        $draft = $this->inShop(fn () => app(Shipments::class)->draft($lead));

        $this->assertContains('Nilai barang belum diketahui: isi harga produk di katalog atau di pesanan lead.', $draft->problems);
        $this->assertSame([], $this->callsTo('/shipment/v2/rates'));
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
        $this->assertSame(310000, $order['cod_price']);
        $this->assertSame('6281234567890', $order['receiver_phone'], 'Everpro takes only 62…');
        $this->assertSame('Sekeloa', $order['receiver_sub_district_name']);
        $this->assertSame('40134', $order['receiver_postal_code']);
        $this->assertArrayNotHasKey('receiver_sub_district_id', $order);

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
        $this->assertCount(1, array_filter($this->calls, fn ($c) => str_ends_with($c['path'], 'TRD2026110500001/cancel')), 'Cancelled by Everpro\'s own number.');

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

        // Waits for the balance instead of failing; no order was taken.
        $this->assertSame(Shipment::PENDING, $shipment->state);
        $this->assertTrue($shipment->waitingBalance());
        $this->assertNull($shipment->order_id);
        $this->assertStringContainsString('Saldo Everpro tidak cukup (perlu Rp 18.000', $shipment->error);
        $this->assertTrue($this->inShop(fn () => $lead->activities()->where('body', 'like', '%Saldo Everpro tidak cukup%Saldo tidak mencukupi%')->exists()));
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
        $this->assertStringContainsString('Mohon siapkan uang *Rp310.000*', $messages[1]);
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
        // Only the newest status waits: "picked up" is old news once the parcel is out for delivery.
        $this->assertSame(1, $this->inShop(fn () => ShipmentNotice::query()->where('state', 'pending')->count()));
        $this->assertSame('Status kiriman sudah berlanjut', $this->inShop(fn () => ShipmentNotice::query()->where('status', 'picked_up')->value('note')));

        Carbon::setTestNow(Carbon::parse('2026-11-06 05:01', 'Asia/Jakarta'));
        $this->artisan('shipping:notices')->assertSuccessful();

        $this->assertCount(0, array_slice($this->sent(), $before), 'The delivery day has passed and the older status is not sent instead.');
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

    // ---- Tracking numbers made outside JITU LEAD ---------------------------------------------------------------

    /** A lead in Dikemas & dikirim whose tracking number was made in the Everpro app. */
    private function packed(string $phone = '6281234567890', array $extra = []): Lead
    {
        return $this->order('cod', $phone, $extra);
    }

    private function link(Lead $lead, string $awb, bool $notify = false, bool $close = true): array
    {
        return $this->inShop(fn () => app(Shipments::class)->link($lead, $awb, 'JNE', $this->adminOf($this->shop), $notify, $close));
    }

    public function test_linking_a_tracking_number_tracks_it_once_without_telling_the_buyer(): void
    {
        $this->configure();
        $lead = $this->packed();
        $before = count($this->sent());
        // Picked up three days ago: history only.
        $this->track[] = ['awb_number' => 'JNE7700112233', 'status' => 'PICKED_UP', 'tracking_history' => [
            ['date' => '2026-11-02T03:00:00Z', 'status' => 'PICKED_UP', 'description' => 'Paket diambil kurir'],
        ]];

        [$shipment, $said] = $this->link($lead, ' jne 7700 112233 ');

        $this->assertSame('JNE7700112233', $shipment->awb);
        $this->assertSame(Shipment::MANUAL, $shipment->source);
        $this->assertNull($shipment->order_id);
        $this->assertSame(ShipmentStatus::PICKED_UP, $shipment->status);
        $this->assertSame('Status sekarang: '.ShipmentStatus::label(ShipmentStatus::PICKED_UP).'.', $said);
        $this->assertSame('JNE7700112233', $lead->fresh()->tracking_number);
        $this->assertSame('JNE', $lead->fresh()->courier);
        $this->assertNotNull($lead->fresh()->shipped_at);
        $this->assertSame($before, count($this->sent()), 'No tracking number message (option off) and no message for a status from before the link.');
        $this->assertSame([], $this->callsTo('/shipment/v2/orders'), 'No order is made at Everpro.');
        $this->assertFalse($shipment->cancellable());

        // Later changes reach the buyer as usual.
        $this->track[] = ['awb_number' => 'JNE7700112233', 'status' => 'ON_DELIVERY', 'tracking_history' => []];
        $this->artisan('shipping:track')->assertSuccessful();
        $this->assertSame(ShipmentStatus::OUT_FOR_DELIVERY, $this->shipment()->status);
        $this->assertCount($before + 1, $this->sent());
        $this->assertStringContainsString('sedang diantar kurir', collect($this->sent())->last());

        // One number, one lead; the format must look like a tracking number.
        $other = $this->packed('6281234567891');
        $this->assertThrows(fn () => $this->link($other, 'JNE7700112233'), CarrierException::class, 'sudah dipakai lead lain');
        $this->assertThrows(fn () => $this->link($other, 'abc'), CarrierException::class, 'Format nomor resi');
    }

    public function test_the_tracking_number_message_goes_only_when_asked_for(): void
    {
        $this->configure();
        $lead = $this->packed();
        $before = count($this->sent());

        $this->link($lead, 'JNE7700112244', notify: true);

        $this->assertCount($before + 1, $this->sent());
        $this->assertStringContainsString('JNE7700112244', collect($this->sent())->last());
    }

    public function test_a_parcel_already_delivered_closes_the_lead_without_any_message(): void
    {
        $this->configure();
        $lead = $this->packed();
        $before = count($this->sent());
        $this->track[] = ['awb_number' => 'JNE7700112255', 'status' => 'DELIVERED', 'tracking_history' => [
            ['date' => '2026-11-01T03:00:00Z', 'status' => 'PICKED_UP', 'description' => 'Paket diambil kurir'],
            ['date' => '2026-11-03T05:00:00Z', 'status' => 'DELIVERED', 'description' => 'Diterima oleh RINA'],
        ]];

        $this->link($lead, 'JNE7700112255');

        $fresh = $this->inShop(fn () => $lead->fresh('stage'));
        $this->assertSame('Selesai', $fresh->stage->name);
        $this->assertSame($before, count($this->sent()));
        $this->assertSame(0, $this->inShop(fn () => ShipmentNotice::query()->count()));
        // Dated to the delivery, so a review follow-up whose time has passed is not sent.
        $this->assertSame('2026-11-03 05:00', $fresh->stage_entered_at->utc()->format('Y-m-d H:i'));
    }

    public function test_a_number_everpro_does_not_know_stops_being_tracked_after_three_tries(): void
    {
        $this->configure();
        $lead = $this->packed();
        $this->unknownAwbs = ['JNE7700112266'];

        [, $said] = $this->link($lead, 'JNE7700112266');
        $this->assertStringContainsString('resi belum dikenali Everpro', $said);

        $this->artisan('shipping:track')->assertSuccessful();
        $this->artisan('shipping:track')->assertSuccessful();

        $shipment = $this->shipment();
        $this->assertSame(3, $shipment->track_failures);
        $this->assertTrue($shipment->untrackable());
        $this->assertStringContainsString('Tidak bisa dilacak', $this->inShop(fn () => (string) ShipmentTimeline::html($shipment)));
        $this->assertSame(1, $this->inShop(fn () => $lead->activities()->where('body', 'like', '%tidak bisa dilacak%')->count()));

        $calls = count(array_filter($this->calls, fn ($c) => str_ends_with($c['path'], '/JNE7700112266/track')));
        $this->artisan('shipping:track')->assertSuccessful();
        $this->assertCount($calls, array_filter($this->calls, fn ($c) => str_ends_with($c['path'], '/JNE7700112266/track')), 'No fourth try.');
    }

    public function test_the_import_matches_by_phone_and_saves_only_what_was_confirmed(): void
    {
        $this->configure();
        $rina = $this->packed('6281234567890', ['name' => 'Rina Kartika']);
        $budi1 = $this->packed('6281234567891', ['name' => 'Budi']);
        // Budi ordered twice: two leads with one number, neither waiting more than the other.
        $budi2 = $this->inShop(fn () => tap(Lead::query()->create(['name' => 'Budi', 'phone' => $budi1->phone, 'stage_id' => $budi1->stage_id]))->refresh());
        $tenantOther = app(ProvisionTenant::class)('Toko Lain', 'toko-lain', User::factory()->create(), 'retail');
        app(CurrentTenant::class)->run($tenantOther, fn () => Lead::query()->create(['name' => 'Orang Lain', 'phone' => PhoneNumber::normalize('6281299990000'), 'stage_id' => (new RetailStages)->stage(RetailStages::SHIPPING)->id]));

        $csv = "No Resi;Nama Penerima;No HP Penerima;Ekspedisi\n"
            ."JNE8800000001;Rina Kartika;0812-3456-7890;JNE\n"
            ."JNE8800000002;Budi;081234567891;SiCepat\n"
            ."JNE8800000003;Orang Lain;081299990000;JNE\n"
            ."xx;Salah;0811;JNE\n";
        $path = tempnam(sys_get_temp_dir(), 'resi');
        file_put_contents($path, $csv);
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        $page = Livewire::test(ImportResi::class)->call('read', $path, 'ekspor.csv');

        $this->assertSame(['awb' => 0, 'name' => 1, 'phone' => 2, 'courier' => 3], $page->get('columns'));
        $matches = $page->get('matches');
        $this->assertSame([ResiImport::MATCHED, ResiImport::AMBIGUOUS, ResiImport::UNMATCHED, ResiImport::INVALID], array_column($matches, 'status'));
        $this->assertSame($rina->id, $matches[0]['lead_id']);
        $this->assertEqualsCanonicalizing([$budi1->id, $budi2->id], array_keys($matches[1]['options']));
        $this->assertArrayNotHasKey(0, array_filter($matches[2]['options'], fn ($label) => str_contains($label, 'Orang Lain')), 'Another agency\'s lead is never offered.');
        $this->assertSame([(string) $rina->id, '', '', ''], $page->get('choices'));

        // The agent picks Budi's second order and skips the rest.
        $page->set('choices.1', (string) $budi2->id)->call('save')->assertNotified('2 resi terhubung');

        $this->assertSame('JNE8800000001', $rina->fresh()->tracking_number);
        $this->assertSame('JNE8800000002', $budi2->fresh()->tracking_number);
        $this->assertSame('SiCepat', $budi2->fresh()->courier);
        $this->assertNull($budi1->fresh()->tracking_number);
        $this->assertSame(2, $this->inShop(fn () => Shipment::query()->where('source', Shipment::MANUAL)->count()));
        $this->assertSame(0, app(CurrentTenant::class)->run($tenantOther, fn () => Shipment::query()->count()));

        // Read again, the saved rows show as already linked.
        $this->assertSame([ResiImport::LINKED, ResiImport::LINKED], array_slice(array_column($page->get('matches'), 'status'), 0, 2));
    }

    public function test_an_xlsx_export_is_read_too(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>AWB Number</t></si><si><t>Receiver Name</t></si><si><t>Rina</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="inlineStr"><is><t>Receiver Phone</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>JNE8800000009</t></is></c><c r="B2" t="s"><v>2</v></c><c r="C2"><v>6281234567890</v></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $rows = SpreadsheetReader::rows($path);

        $this->assertSame([['AWB Number', 'Receiver Name', 'Receiver Phone'], ['JNE8800000009', 'Rina', '6281234567890']], $rows);
        $this->assertSame(['awb' => 0, 'name' => 1, 'phone' => 2, 'courier' => null], ResiImport::guessColumns($rows[0]));
    }

    // ---- Everpro's own order numbers (TRD…) ----------------------------------------------------------------------

    /** Shipment #16 as GET /shipment/v1/orders showed it in production (lead #358). */
    private static function trdOrder(array $extra = []): array
    {
        return $extra + [
            'shipment_order_no' => 'TRD2026110602264', 'client_order_no' => 'TRD2026110602264', 'order_reference_id' => '', 'awb_number' => '001798295816',
            'status_id' => 300, 'status' => 'WAITING FOR PICK UP', 'order_date' => '2026-11-05 09:40:00', 'pickup_time' => 1793937600,
            'pickup_time_date' => '2026-11-05', 'pickup_time_range' => '14:00 - 16:00',
            'receiver_name' => 'Gustinah', 'receiver_phone_number' => '6281234567890', 'receiver_location_detail' => 'Sekeloa, Coblong, Kota Bandung', 'receiver_address_note' => '',
            'package_desc' => 'Tas Badminton Hitam x2', 'package_price' => 300000, 'courier_detail' => 'SiCepat Reguler',
            'shipment_price' => 52900, 'cod_fee' => 4795, 'total_cod' => 144000, 'total_cod_disbursement' => 86305, 'is_cod' => true, 'cashback' => 13225,
            'label_url' => '', 'insurance_price' => 0, 'weight' => 2, 'length' => 30, 'width' => 20, 'height' => 10,
        ];
    }

    public function test_a_booking_answered_with_data_null_is_found_in_everpros_order_list(): void
    {
        Storage::fake('local');
        $this->configure();
        $this->orderDataNull = true;
        // Someone else's parcel, and the same buyer's parcel of yesterday, are never taken.
        $this->remoteOrders = [
            self::trdOrder(['shipment_order_no' => 'TRD2026110500777', 'awb_number' => '001700000001', 'receiver_phone_number' => '6281299998888', 'total_cod' => 310000, 'order_date' => '2026-11-05 09:59:00']),
            self::trdOrder(['shipment_order_no' => 'TRD2026110400123', 'awb_number' => '001700000002', 'total_cod' => 310000, 'order_date' => '2026-11-04 15:00:00']),
        ];
        $lead = $this->order('cod');

        $shipment = $this->book($lead);

        $this->assertSame(Shipment::SHIPPED, $shipment->state);
        $this->assertSame(['TRD2026110500003', 'JNE0001236'], [$shipment->provider_order_no, $shipment->awb], 'The new order, under Everpro\'s own number.');
        $this->assertSame([18000, 4000, 288000, 2000], [$shipment->price, $shipment->cod_fee, $shipment->cod_disbursement, $shipment->cashback]);
        $this->assertSame([], array_filter($this->calls, fn ($c) => str_ends_with($c['path'], '/generate')), 'Already has its number: nothing generated.');
        $this->assertNotNull($shipment->label_path, 'Label asked for by the tracking number though the list has no label_url.');
        $this->assertTrue(collect($this->calls)->contains(fn ($c) => $c['path'] === '/shipment/v1/orders/JNE0001236/label'));
        $this->assertCount(1, array_filter($this->sent(), fn ($b) => str_contains((string) $b, 'Nomor resi: JNE0001236')));
        $this->assertSame(['Ongkir Rp18.000', 'Biaya COD Rp4.000', 'Cair Rp288.000', 'Cashback Rp2.000'], ShipmentResource::fees($shipment));

        // Cancelled by Everpro's number; the next parcel of the same buyer takes the next order, never this one again.
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        $this->inShop(fn () => app(Shipments::class)->cancel($shipment, $this->adminOf($this->shop)));
        $this->assertTrue(collect($this->calls)->contains(fn ($c) => $c['path'] === '/shipment/v1/orders/TRD2026110500003/cancel'));
        $next = $this->book($lead->fresh());
        $this->assertSame('TRD2026110500004', $next->provider_order_no);
    }

    public function test_a_prepaid_order_without_a_number_yet_is_generated_by_everpros_number(): void
    {
        $this->configure();
        $this->orderDataNull = true;
        $this->noAwb = true;
        $shipment = $this->book($this->order('transfer', extra: ['paid_at' => now()]));

        $generate = array_values(array_filter($this->calls, fn ($c) => str_ends_with($c['path'], '/generate')));
        $this->assertSame(['/shipment/v1/orders/TRD2026110500001/generate'], array_column($generate, 'path'), 'Never by our JL-… number, which Everpro does not know.');
        $this->assertSame([Shipment::ORDERED, 'TRD2026110500001'], [$shipment->state, $shipment->provider_order_no]);
        $this->assertFalse($shipment->is_cod);
    }

    public function test_coba_lagi_fixes_shipment_16_from_everpros_order_list_and_tells_the_buyer_once(): void
    {
        Storage::fake('local');
        $this->configure();
        $lead = $this->order('cod', '6281234567890', ['name' => 'Gustinah']);
        // As in production: booked before this fix, "data": null, then 404 for our JL-… number until 30 minutes passed.
        $shipment = $this->inShop(fn () => Shipment::query()->forceCreate([
            'lead_id' => $lead->id, 'provider' => 'everpro', 'sequence' => 1, 'client_order_no' => 'JL-7-'.$lead->id.'-1', 'rate_code' => 'REG',
            'courier' => 'SiCepat REG', 'state' => Shipment::ORDERED, 'is_cod' => true, 'cod_amount' => 144000, 'price' => 16000, 'weight_grams' => 2000,
            'request' => ['description' => 'Tas Badminton Hitam x2', 'awb_requested_at' => now()->subMinutes(35)->toIso8601String(), 'awb_tries' => 15],
            'error' => 'Nomor resi belum terbit setelah 30 menit. Coba lagi atau cek di Everpro.',
            'created_at' => now()->subMinutes(25),
        ]));
        $this->remoteOrders = [
            self::trdOrder(['shipment_order_no' => 'TRD2026110500900', 'awb_number' => '001700000003', 'receiver_phone_number' => '6281299998888', 'order_date' => '2026-11-05 09:50:00']),
            self::trdOrder(),
        ];
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(ListShipments::class)->set('activeTab', 'action')->callTableAction('retry', $shipment)->assertNotified('Resi 001798295816 terbit.');

        $shipment = $shipment->fresh();
        $this->assertSame([Shipment::SHIPPED, '001798295816', 'TRD2026110602264', null], [$shipment->state, $shipment->awb, $shipment->provider_order_no, $shipment->error]);
        $this->assertSame([52900, 4795, 86305, 13225], [$shipment->price, $shipment->cod_fee, $shipment->cod_disbursement, $shipment->cashback]);
        $this->assertNotNull($shipment->label_path, 'Label downloaded.');
        $this->assertSame([$shipment->id], $this->inShop(fn () => Shipment::query()->tab('unprinted')->pluck('id')->all()), 'In Belum dicetak.');
        $this->assertSame('001798295816', $lead->fresh()->tracking_number);
        $this->assertSame([], array_filter($this->calls, fn ($c) => str_contains($c['path'], 'JL-')), 'Our own number is never asked for again.');
        $this->assertSame([], $this->callsTo('/shipment/v2/orders'), 'Never booked twice.');

        // Running the repair afterwards changes nothing and sends nothing more.
        $this->artisan('shipping:fix-everpro-orders', ['--apply' => true])->assertSuccessful();
        $this->assertCount(1, array_filter($this->sent(), fn ($b) => str_contains((string) $b, 'Nomor resi: 001798295816')), 'The resi message, once.');
    }

    public function test_coba_lagi_on_a_failed_parcel_that_everpro_did_book_never_books_it_twice(): void
    {
        Storage::fake('local');
        $this->configure();
        $lead = $this->order('cod', '6281234567890', ['name' => 'Gustinah']);
        $shipment = $this->inShop(fn () => Shipment::query()->forceCreate([
            'lead_id' => $lead->id, 'provider' => 'everpro', 'sequence' => 1, 'client_order_no' => 'JL-7-'.$lead->id.'-1', 'rate_code' => 'REG',
            'courier' => 'SiCepat REG', 'state' => Shipment::FAILED, 'is_cod' => true, 'cod_amount' => 144000, 'weight_grams' => 2000,
            'request' => [], 'error' => 'order not found', 'created_at' => now()->subMinutes(25),
        ]));
        $this->remoteOrders = [self::trdOrder()];
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(ListShipments::class)->set('activeTab', 'action')->callTableAction('retry', $shipment)->assertNotified('Resi 001798295816 terbit.');

        $this->assertSame([], $this->callsTo('/shipment/v2/orders'), 'Found in Everpro\'s list: not booked again.');
        $this->assertSame([Shipment::SHIPPED, 'TRD2026110602264'], [$shipment->fresh()->state, $shipment->fresh()->provider_order_no]);
    }

    /** A booked parcel the order list never gave a number for, as in production (#16). */
    private function stuck(Lead $lead, array $extra = []): Shipment
    {
        return $this->inShop(fn () => Shipment::query()->forceCreate($extra + [
            'lead_id' => $lead->id, 'provider' => 'everpro', 'sequence' => 1, 'client_order_no' => 'JL-7-'.$lead->id.'-1', 'rate_code' => 'REG',
            'courier' => 'SiCepat REG', 'state' => Shipment::ORDERED, 'is_cod' => true, 'cod_amount' => 144000, 'weight_grams' => 2000,
            'request' => ['description' => 'Tas Badminton Hitam x2', 'awb_requested_at' => now()->subMinutes(35)->toIso8601String()],
            'error' => 'Nomor resi belum terbit setelah 30 menit.', 'created_at' => now()->subMinutes(25),
        ]));
    }

    public function test_an_order_date_without_a_time_is_compared_by_day(): void
    {
        Storage::fake('local');
        $this->configure();
        $lead = $this->order('cod', '6281234567890', ['name' => 'Gustinah']);
        $shipment = $this->stuck($lead);
        // Booked 09:35 today; Everpro's list says only "2026-11-05" (midnight): same day matches, the day before does not.
        $this->remoteOrders = [
            self::trdOrder(['shipment_order_no' => 'TRD2026110400001', 'awb_number' => '001700000009', 'order_date' => '2026-11-04']),
            self::trdOrder(['order_date' => '2026-11-05']),
        ];

        $this->artisan('shipping:fix-everpro-orders', ['--waiting' => true, '--apply' => true])->assertSuccessful();

        $this->assertSame([Shipment::SHIPPED, 'TRD2026110602264', '001798295816'], [$shipment->fresh()->state, $shipment->fresh()->provider_order_no, $shipment->fresh()->awb]);
        $this->assertCount(1, array_filter($this->sent(), fn ($b) => str_contains((string) $b, 'Nomor resi: 001798295816')));
    }

    public function test_only_yesterdays_order_is_never_taken(): void
    {
        $this->configure();
        $lead = $this->order('cod', '6281234567890', ['name' => 'Gustinah']);
        $shipment = $this->stuck($lead);
        $this->remoteOrders = [self::trdOrder(['order_date' => '2026-11-04'])];

        $this->artisan('shipping:fix-everpro-orders', ['--waiting' => true, '--apply' => true])->assertSuccessful();

        $this->assertSame([Shipment::ORDERED, null], [$shipment->fresh()->state, $shipment->fresh()->provider_order_no]);
    }

    public function test_two_equal_orders_on_the_same_day_are_not_guessed_and_the_agent_picks_one(): void
    {
        Storage::fake('local');
        $this->configure();
        $lead = $this->order('cod', '6281234567890', ['name' => 'Gustinah']);
        $shipment = $this->stuck($lead);
        $this->remoteOrders = [
            self::trdOrder(['shipment_order_no' => 'TRD2026110500002', 'awb_number' => '001700000002', 'order_date' => '2026-11-05']),
            self::trdOrder(['shipment_order_no' => 'TRD2026110500001', 'awb_number' => '001700000001', 'order_date' => '2026-11-05']),
        ];

        $this->artisan('shipping:fix-everpro-orders', ['--waiting' => true, '--apply' => true])->assertSuccessful();

        $shipment = $shipment->fresh();
        $this->assertSame([Shipment::ORDERED, null], [$shipment->state, $shipment->provider_order_no], 'Not matched on a guess.');
        $this->assertEqualsCanonicalizing(['TRD2026110500001', 'TRD2026110500002'], array_keys($shipment->request['order_candidates']));
        $this->assertStringContainsString('Ada 2 order Everpro yang sama-sama cocok', $shipment->error);
        $this->assertSame([], array_filter($this->sent(), fn ($b) => str_contains((string) $b, 'Nomor resi')));

        // The schedule leaves it to a person; the agent picks in Coba lagi.
        $calls = count($this->calls);
        $this->artisan('shipping:fix-everpro-orders', ['--waiting' => true, '--apply' => true])->assertSuccessful();
        $this->assertSame($calls, count($this->calls), 'Left alone.');

        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        Livewire::test(ListShipments::class)->set('activeTab', 'action')
            ->callTableAction('retry', $shipment, data: ['order' => 'TRD2026110500002'])->assertNotified('Resi 001700000002 terbit.');

        $shipment = $shipment->fresh();
        $this->assertSame([Shipment::SHIPPED, 'TRD2026110500002', '001700000002'], [$shipment->state, $shipment->provider_order_no, $shipment->awb]);
        $this->assertArrayNotHasKey('order_candidates', (array) $shipment->request);
        $this->assertCount(1, array_filter($this->sent(), fn ($b) => str_contains((string) $b, 'Nomor resi: 001700000002')));
    }

    public function test_the_repair_command_matches_a_parcel_left_without_a_number(): void
    {
        Storage::fake('local');
        $this->configure();
        $lead = $this->order('cod', '6281234567890', ['name' => 'Gustinah']);
        $shipment = $this->inShop(fn () => Shipment::query()->forceCreate([
            'lead_id' => $lead->id, 'provider' => 'everpro', 'sequence' => 1, 'client_order_no' => 'JL-7-'.$lead->id.'-1', 'rate_code' => 'REG',
            'courier' => 'SiCepat REG', 'state' => Shipment::ORDERED, 'is_cod' => true, 'cod_amount' => 144000, 'weight_grams' => 2000,
            'request' => ['awb_requested_at' => now()->subMinutes(35)->toIso8601String()], 'error' => 'Nomor resi belum terbit setelah 30 menit.', 'created_at' => now()->subMinutes(25),
        ]));
        $this->remoteOrders = [self::trdOrder()];

        $this->artisan('shipping:fix-everpro-orders')->expectsOutputToContain('#'.$shipment->id.': TRD2026110602264 → 001798295816')->assertSuccessful();
        $this->assertSame(Shipment::ORDERED, $shipment->fresh()->state, 'A dry run changes nothing.');
        $this->assertSame([], array_filter($this->sent(), fn ($b) => str_contains((string) $b, '001798295816')), 'Nothing sent on a dry run.');

        $this->artisan('shipping:fix-everpro-orders', ['--apply' => true])->assertSuccessful();
        $this->assertSame([Shipment::SHIPPED, '001798295816'], [$shipment->fresh()->state, $shipment->fresh()->awb]);
        $this->assertCount(1, array_filter($this->sent(), fn ($b) => str_contains((string) $b, 'Nomor resi: 001798295816')));
    }

    public function test_imported_trd_numbers_are_swapped_for_the_real_tracking_number_without_a_message(): void
    {
        $this->configure();
        $this->remoteOrders = [
            self::trdOrder(['shipment_order_no' => 'TRD2026110508728', 'client_order_no' => 'TRD2026110508728', 'awb_number' => 'JO0330735222', 'courier_detail' => 'J&T Express EZ', 'receiver_phone_number' => '6281234567891']),
            self::trdOrder(['shipment_order_no' => 'TRD2026110508509', 'client_order_no' => 'TRD2026110508509', 'awb_number' => '6329732600175284', 'courier_detail' => 'SiCepat Reguler', 'receiver_phone_number' => '6281234567892']),
        ];
        // Imported on 6 Nov, before this fix: Everpro's order number stored as the tracking number.
        $broken = [];
        foreach (['6281234567891' => 'TRD2026110508728', '6281234567892' => 'TRD2026110508509', '6281234567893' => 'TRD2026110500000'] as $phone => $trd) {
            $lead = $this->packed($phone, ['name' => 'Pembeli '.substr($phone, -2)]);
            $this->inShop(fn () => $lead->forceFill(['tracking_number' => $trd, 'courier' => 'JNE'])->save());
            $broken[$trd] = $this->inShop(fn () => Shipment::query()->forceCreate(['lead_id' => $lead->id, 'provider' => 'everpro', 'source' => Shipment::MANUAL, 'sequence' => 1,
                'client_order_no' => 'MAN-'.$this->shop->id.'-'.$lead->id.'-1', 'awb' => $trd, 'courier' => 'JNE', 'state' => Shipment::SHIPPED,
                'status' => ShipmentStatus::AWB_CREATED, 'awb_notified_at' => now(), 'linked_at' => now(), 'track_failures' => 3, 'untrackable_at' => now()]));
        }
        $before = count($this->sent());

        $this->artisan('shipping:fix-everpro-orders')->expectsOutputToContain('TRD2026110508728 → JO0330735222')->assertSuccessful();
        $this->assertSame('TRD2026110508728', $broken['TRD2026110508728']->fresh()->awb, 'A dry run changes nothing.');

        $this->artisan('shipping:fix-everpro-orders', ['--apply' => true])
            ->expectsOutputToContain('TRD2026110500000 → dilewati, tidak ditemukan di daftar order Everpro')->assertSuccessful();

        $fixed = $broken['TRD2026110508728']->fresh();
        $this->assertSame(['JO0330735222', 'TRD2026110508728', 'J&T Express EZ', null, 0], [$fixed->awb, $fixed->provider_order_no, $fixed->courier, $fixed->untrackable_at, $fixed->track_failures]);
        $this->assertSame('JO0330735222', $fixed->lead->tracking_number);
        $this->assertSame('6329732600175284', $broken['TRD2026110508509']->fresh()->lead->tracking_number);
        $this->assertSame('TRD2026110500000', $broken['TRD2026110500000']->fresh()->awb, 'Unknown: left for a person.');
        $this->assertSame($before, count($this->sent()), 'Nothing sent to any buyer.');

        // Linking or importing a TRD… number from now on stores the real tracking number.
        $rina = $this->packed('6281234567890');
        $this->remoteOrders[] = self::trdOrder(['shipment_order_no' => 'TRD2026110509999', 'awb_number' => 'JX1234567890', 'courier_detail' => 'J&T Cargo']);
        $this->travel(5)->minutes();
        [$linked] = $this->link($rina, 'trd2026110509999');
        $this->assertSame(['JX1234567890', 'TRD2026110509999', 'J&T Cargo'], [$linked->awb, $linked->provider_order_no, $linked->courier]);
        $this->assertSame('JX1234567890', $rina->fresh()->tracking_number);
        $this->assertSame($before, count($this->sent()));
        $this->assertSame(ResiImport::LINKED, $this->inShop(fn () => ResiImport::match([['TRD2026110509999']], ['awb' => 0, 'name' => null, 'phone' => null, 'courier' => null]))[0]['status'], 'Known by its order number too.');

        $this->expectException(CarrierException::class);
        $this->expectExceptionMessage('TRD2026110500000 adalah nomor order Everpro, bukan nomor resi');
        $this->link($this->packed('6281234567894'), 'TRD2026110500000');
    }

    // ---- Bulk ----------------------------------------------------------------------------------------------------

    public function test_bulk_buat_resi_and_labels_on_the_lead_list(): void
    {
        $this->configure();
        $a = $this->order('cod', '6281234567890');
        $b = $this->order('transfer', '6281234567891', ['paid_at' => now()]);
        $c = $this->order('transfer', '6281234567892', ['shipping_address' => 'Bdg', 'paid_at' => now()]);
        // Never assumed in bulk: no way of paying, one only read from the chat, a transfer nobody has seen
        $d = $this->order('cod', '6281234567893', ['name' => 'Dewi Blank', 'payment_type' => null]);
        $e = $this->order('cod', '6281234567894', ['name' => 'Eka Chat', 'payment_type_source' => 'chat']);
        $f = $this->order('transfer', '6281234567895', ['name' => 'Fajar Belum Bayar']);
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(ListLeads::class)->callTableBulkAction('bulkCreateShipments', [$a, $b, $c, $d, $e, $f]);
        $notices = new Notifications;
        $notices->mount();
        $body = (string) $notices->notifications->first(fn ($n) => $n->getTitle() === '2 kiriman dibuat')?->getBody();
        $this->assertStringContainsString('Dewi Blank: cara bayar belum dipastikan (COD atau transfer)', $body);
        $this->assertStringContainsString('Eka Chat: cara bayar belum dipastikan', $body);
        $this->assertStringContainsString('Fajar Belum Bayar: pembayaran transfer belum dikonfirmasi', $body);

        $this->assertSame(2, $this->inShop(fn () => Shipment::query()->count()));
        $this->assertSame([$a->id, $b->id], $this->inShop(fn () => Shipment::query()->orderBy('lead_id')->pluck('lead_id')->all()));

        Livewire::test(ListLeads::class)->callTableBulkAction('bulkShipmentLabels', [$a, $b])->assertNotified('2 label digabung · 0 dilewati');
    }

    // ---- Bulk labels in one PDF ------------------------------------------------------------------------------------

    /** A lead named $name with a parcel $awb (null: no tracking number yet). */
    private function parcel(string $name, string $phone, ?string $awb): Lead
    {
        $lead = $this->order('transfer', $phone, ['name' => $name]);
        $this->inShop(fn () => Shipment::query()->create(['lead_id' => $lead->id, 'provider' => 'everpro', 'client_order_no' => 'QS-'.$phone,
            'awb' => $awb, 'state' => $awb ? Shipment::SHIPPED : Shipment::PENDING]));

        return $lead;
    }

    /** @return list<array{0: float, 1: float}> width and height (mm) of each page */
    private static function pages(string $pdf): array
    {
        $reader = new Fpdi;
        $count = $reader->setSourceFile(StreamReader::createByString($pdf));

        return array_map(fn ($page) => array_map(fn ($v) => round($v), array_values(array_slice($reader->getTemplateSize($reader->importPage($page)), 0, 2))), range(1, $count));
    }

    private function sheetFile(string $url): string
    {
        $file = basename((string) parse_url($url, PHP_URL_PATH));

        return Storage::disk('local')->get(LabelSheet::DIR.'/'.$this->shop->id.'/'.$file);
    }

    public function test_three_labels_become_one_pdf_of_three_pages_in_table_order(): void
    {
        Storage::fake('local');
        $this->configure();
        $leads = [$this->parcel('Ani', '6281200000001', 'AWB120'), $this->parcel('Budi', '6281200000002', 'AWB100'), $this->parcel('Citra', '6281200000003', 'AWB80')];
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        $sheet = app(LabelSheet::class)->make($leads);

        $this->assertSame(['Ani · AWB120', 'Budi · AWB100', 'Citra · AWB80'], $sheet['merged']);
        $this->assertSame([], $sheet['skipped']);
        $this->assertSame([[120.0, 150.0], [100.0, 150.0], [80.0, 150.0]], self::pages($this->sheetFile($sheet['url'])), 'One page per label, own size, in order.');
        $this->assertCount(1, Storage::disk('local')->allFiles(LabelSheet::DIR));
        $this->assertMatchesRegularExpression('#/label-kiriman/'.$this->shop->id.'/[A-Za-z0-9]{40}\.pdf\?expires=#', $sheet['url']);

        $this->get($sheet['url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');

        Livewire::test(ListLeads::class)->callTableBulkAction('bulkShipmentLabels', $leads)->assertNotified('3 label digabung · 0 dilewati');
    }

    public function test_leads_without_a_tracking_number_or_whose_label_fails_are_skipped_and_named(): void
    {
        Storage::fake('local');
        $this->configure();
        Log::spy();
        $leads = [
            $this->parcel('Ani', '6281200000001', 'AWB100'),
            $this->parcel('Budi', '6281200000002', null),
            $this->parcel('Citra', '6281200000003', 'AWB-DOWN'),
            $this->parcel('Dewi', '6281200000004', 'AWB-HTML'),
        ];
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        $sheet = app(LabelSheet::class)->make($leads);

        $this->assertSame(['Ani · AWB100'], $sheet['merged']);
        $this->assertSame([['name' => 'Budi', 'reason' => 'belum ada resi'], ['name' => 'Citra', 'reason' => 'label gagal diambil'], ['name' => 'Dewi', 'reason' => 'label gagal diambil']], $sheet['skipped']);
        $this->assertCount(1, self::pages($this->sheetFile($sheet['url'])));
        Log::shouldHaveReceived('warning')->with('Shipping label could not be fetched for the bulk print', \Mockery::on(fn ($ctx) => array_keys($ctx) === ['tenant', 'lead', 'step'] && $ctx['step'] === 'download'));

        Livewire::test(ListLeads::class)->callTableBulkAction('bulkShipmentLabels', $leads)->assertNotified('1 label digabung · 3 dilewati');

        // Nothing to print: no file, only the reasons.
        $none = app(LabelSheet::class)->make([$leads[1]]);
        $this->assertNull($none['url']);
        Livewire::test(ListLeads::class)->callTableBulkAction('bulkShipmentLabels', [$leads[1]])->assertNotified('0 label digabung · 1 dilewati');
    }

    public function test_one_print_takes_at_most_the_configured_number_of_labels(): void
    {
        Storage::fake('local');
        config(['carriers.label_max' => 2]);
        $this->configure();
        $leads = [$this->parcel('Ani', '6281200000001', 'AWB100'), $this->parcel('Budi', '6281200000002', 'AWB101'), $this->parcel('Citra', '6281200000003', 'AWB102')];
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        $sheet = app(LabelSheet::class)->make($leads);

        $this->assertCount(2, $sheet['merged']);
        $this->assertSame([['name' => 'Citra', 'reason' => 'lebih dari 2 label sekali cetak']], $sheet['skipped']);
        $this->assertSame(2, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'labels.example.com'))->count());
    }

    public function test_the_pdf_link_expires_and_is_only_for_members_of_the_agency(): void
    {
        Storage::fake('local');
        $this->configure();
        $lead = $this->parcel('Ani', '6281200000001', 'AWB100');
        $admin = $this->adminOf($this->shop);
        $this->actingInTenant($admin, $this->shop);
        $url = app(LabelSheet::class)->make([$lead])['url'];

        $this->get($url)->assertOk();

        // Another agency's people, and visitors who are not signed in, get nothing.
        $other = app(ProvisionTenant::class)('Toko Lain', 'toko-lain', User::factory()->create(), 'retail');
        $this->actingAs($this->adminOf($other))->get($url)->assertNotFound();
        auth()->logout();
        $this->get($url)->assertNotFound();

        // A changed link, and the same link after an hour, are refused.
        $this->actingAs($admin)->get(str_replace('/'.$this->shop->id.'/', '/'.$other->id.'/', $url))->assertForbidden();
        $this->travel(61)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_print_runs_and_kept_labels_are_deleted_after_their_keeping_time(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put(LabelSheet::DIR.'/1/old.pdf', '%PDF');
        $disk->put(LabelSheet::DIR.'/1/new.pdf', '%PDF');
        touch($disk->path(LabelSheet::DIR.'/1/old.pdf'), now()->subDays(31)->getTimestamp());
        $old = LabelStore::DIR.'/1/'.now()->subDays(91)->toDateString().'/AWB1.pdf';
        $recent = LabelStore::DIR.'/1/'.now()->subDays(10)->toDateString().'/AWB2.pdf';
        $disk->put($old, '%PDF');
        $disk->put($recent, '%PDF');

        $this->artisan('shipping:labels-prune')->assertSuccessful();

        $this->assertSame([LabelSheet::DIR.'/1/new.pdf'], $disk->allFiles(LabelSheet::DIR));
        $this->assertSame([$recent], $disk->allFiles(LabelStore::DIR), 'Labels are kept 90 days');
    }

    // ---- Resi otomatis -------------------------------------------------------------------------------------------

    /** A paid-for order with a full address (RT/RW too) that an admin moves into Dikemas & dikirim. */
    private function readyOrder(string $payment = 'cod', string $phone = '6281234567890', array $extra = [], array $parts = []): Lead
    {
        $parts += ['street' => 'Jl. Mawar', 'house' => '5', 'rt_rw' => '001/002', 'kelurahan' => 'Sekeloa', 'kecamatan' => 'Coblong', 'kota' => 'Kota Bandung', 'provinsi' => 'Jawa Barat', 'kode_pos' => '40134'];
        $lead = $this->order($payment, $phone, $extra + ['shipping_address_parts' => $parts, 'stage_id' => $this->inShop(fn () => (new RetailStages)->stage(RetailStages::PAYMENT)->id)]);

        return $this->inShop(fn () => app(MoveLeadToStage::class)($lead, (new RetailStages)->stage(RetailStages::SHIPPING), $this->adminOf($this->shop))->fresh());
    }

    private function autoOn(array $auto = []): void
    {
        $this->configure(settings: ['auto' => $auto + ['enabled' => true]]);
    }

    private function runAuto(int $minutes = 31): void
    {
        $this->travel($minutes)->minutes();
        $this->artisan('shipping:auto-resi')->assertSuccessful();
    }

    public function test_resi_otomatis_is_off_by_default(): void
    {
        $this->configure();
        $lead = $this->readyOrder();

        $this->assertNull($lead->auto_shipment_state);
        $this->runAuto();
        $this->assertSame([], $this->callsTo('/shipment/v2/orders'));
    }

    public function test_an_eligible_lead_gets_its_resi_after_the_wait_once_with_its_label_kept(): void
    {
        Storage::fake('local');
        $this->autoOn();
        $lead = $this->readyOrder();

        $this->assertSame(AutoShipments::SCHEDULED, $lead->auto_shipment_state);
        $this->assertSame(30, AutoShipments::minutesLeft($lead));
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->assertActionVisible('cancelAutoShipment');
        Livewire::test(Inbox::class, ['leadId' => $lead->id])->assertSee('Resi otomatis dalam 30 menit');

        // Not before the wait is over
        $this->runAuto(10);
        $this->assertSame([], $this->callsTo('/shipment/v2/orders'));

        $this->runAuto(21);
        $this->runAuto(5);
        $this->assertCount(1, $this->callsTo('/shipment/v2/orders'), 'Booked once.');

        $shipment = $this->shipment();
        $this->assertTrue($shipment->automatic);
        $this->assertSame('JNE0001234', $shipment->awb);
        $lead = $this->inShop(fn () => $lead->fresh());
        $this->assertSame(AutoShipments::DONE, $lead->auto_shipment_state);
        $this->assertSame('JNE0001234', $lead->tracking_number);

        $activity = $this->inShop(fn () => $lead->activities()->where('type', 'shipment_auto')->sole());
        $this->assertStringStartsWith('Resi dibuat otomatis: ', $activity->body);
        $this->assertStringContainsString('COD Rp310.000', $activity->body);
        $this->assertSame(310000, $activity->meta['cod']);

        // The label is kept and the parcel waits under "Belum dicetak"
        $this->assertNotNull($shipment->label_path);
        Storage::disk('local')->assertExists($shipment->label_path);
        $this->assertStringStartsWith(LabelStore::DIR.'/'.$this->shop->id.'/', $shipment->label_path);
        $this->assertSame([$shipment->id], $this->inShop(fn () => Shipment::query()->tab('unprinted')->pluck('id')->all()));
        Livewire::test(Inbox::class, ['leadId' => $lead->id])->assertSee('Order OK · siap cetak')->assertDontSee('Resi otomatis dalam');
    }

    public function test_cancelled_during_the_wait_no_resi_is_made(): void
    {
        $this->autoOn();
        $lead = $this->readyOrder();
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->callAction('cancelAutoShipment');
        $this->runAuto();

        $this->assertSame([], $this->callsTo('/shipment/v2/orders'));
        $this->assertSame(AutoShipments::CANCELLED, $this->inShop(fn () => $lead->fresh()->auto_shipment_state));
    }

    public function test_resi_otomatis_refuses_a_dusun_address_without_rt_rw_or_landmark_but_never_holds_one_with_rt_rw(): void
    {
        $this->autoOn();
        $dusun = $this->readyOrder('cod', '6281234567811', ['name' => 'Dusun Tanpa Patokan', 'shipping_address' => 'Dusun Krajan, Sekeloa, Coblong, Kota Bandung 40134'],
            ['rt_rw' => null, 'house' => null, 'dusun' => 'Krajan']);
        $zero = $this->readyOrder('cod', '6281234567812', ['name' => 'RT Nol', 'shipping_address' => 'Dusun Krajan RT 000/000, Sekeloa, Coblong, Kota Bandung 40134'],
            ['rt_rw' => '000/000', 'house' => null, 'dusun' => 'Krajan']);
        $landmark = $this->readyOrder('cod', '6281234567813', ['name' => 'Dusun Patokan'], ['rt_rw' => null, 'house' => null, 'dusun' => 'Krajan', 'landmark' => 'Depan masjid']);
        $rtRw = $this->readyOrder('cod', '6281234567814', ['name' => 'RT Tanpa Patokan'], ['rt_rw' => '001/002', 'house' => null]);

        // Dusun without RT/RW and without landmark: belum lengkap, refused with the reason
        foreach ([$dusun, $zero] as $lead) {
            $this->assertSame(AutoShipments::SKIPPED, $lead->auto_shipment_state);
            $this->assertStringContainsString(AddressCheck::PROBLEM, $lead->auto_shipment_note);
        }

        // Dusun with a landmark, and RT/RW without one: lengkap
        $this->assertSame(AutoShipments::SCHEDULED, $landmark->auto_shipment_state, (string) $landmark->auto_shipment_note);
        $this->assertSame(AutoShipments::SCHEDULED, $rtRw->auto_shipment_state, (string) $rtRw->auto_shipment_note);
        $this->assertSame([$landmark->id, $rtRw->id], $this->inShop(fn () => AutoShipments::scopeReady(Lead::query())->orderBy('id')->pluck('id')->all()), 'the "Siap resi" filter agrees');

        // Buat resi asks the agent to complete it
        $this->assertContains(AddressCheck::MESSAGE, $this->inShop(fn () => app(Shipments::class)->draft($dusun)->problems));
        $this->assertNotContains(AddressCheck::MESSAGE, $this->inShop(fn () => app(Shipments::class)->draft($rtRw)->problems));
    }

    public function test_an_unpaid_transfer_or_a_doubtful_address_is_left_to_the_agent_with_the_reason(): void
    {
        $this->autoOn();
        $unpaid = $this->readyOrder('transfer', '6281234567801', ['name' => 'Belum Bayar']);
        $noRt = $this->readyOrder('cod', '6281234567802', ['name' => 'Tanpa RT'], ['rt_rw' => null, 'house' => null, 'dusun' => 'Krajan']);
        $chat = $this->readyOrder('cod', '6281234567803', ['name' => 'Dari Chat', 'payment_type_source' => 'chat']);
        $unsure = $this->readyOrder('cod', '6281234567804', ['name' => 'Kelurahan Lain'], ['kelurahan' => 'Sukamaju']);

        $this->assertSame(AutoShipments::SKIPPED, $unpaid->auto_shipment_state);
        $this->assertStringContainsString('transfer belum dikonfirmasi lunas', $unpaid->auto_shipment_note);
        $this->assertStringContainsString(AddressCheck::PROBLEM, $noRt->auto_shipment_note);
        $this->assertStringContainsString('cara bayar belum dipastikan agen', $chat->auto_shipment_note);

        // The provider does not know the kelurahan under that postal code: not guessed
        $this->assertSame(AutoShipments::SCHEDULED, $unsure->auto_shipment_state);
        $this->runAuto();
        $unsure = $this->inShop(fn () => $unsure->fresh());
        $this->assertSame(AutoShipments::SKIPPED, $unsure->auto_shipment_state);
        $this->assertStringContainsString('Buat resi manual', $unsure->auto_shipment_note);
        $this->assertSame([], $this->callsTo('/shipment/v2/orders'));

        // The lead list: "Perlu dilengkapi" and the reason column
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        Livewire::test(ListLeads::class)->filterTable('resi', 'incomplete')
            ->assertCanSeeTableRecords([$unpaid, $noRt, $chat])->assertCanNotSeeTableRecords([$unsure])
            ->assertTableColumnStateSet('resi_check', 'Perlu dilengkapi: transfer belum dikonfirmasi lunas', $unpaid);
        Livewire::test(ListLeads::class)->filterTable('resi', 'ready')
            ->assertCanSeeTableRecords([$unsure])->assertCanNotSeeTableRecords([$unpaid, $noRt, $chat]);
    }

    public function test_the_daily_limit_and_the_cod_cap_leave_the_rest_to_a_manual_click(): void
    {
        $this->autoOn(['daily_limit' => 2, 'cod_max' => 200000]);
        $a = $this->readyOrder('transfer', '6281234567811', ['paid_at' => now()]);
        $this->travel(1)->minutes();
        $cod = $this->readyOrder('cod', '6281234567812');
        $this->travel(1)->minutes();
        $b = $this->readyOrder('transfer', '6281234567813', ['payment_proof_at' => now()]);
        $this->travel(1)->minutes();
        $c = $this->readyOrder('transfer', '6281234567814', ['paid_at' => now()]);

        $this->runAuto();

        $this->assertCount(2, $this->callsTo('/shipment/v2/orders'));
        $state = fn (Lead $l) => $this->inShop(fn () => $l->fresh());
        $this->assertSame([AutoShipments::DONE, AutoShipments::DONE], [$state($a)->auto_shipment_state, $state($b)->auto_shipment_state]);
        $this->assertStringContainsString('nilai COD Rp310.000 di atas batas Rp200.000', $state($cod)->auto_shipment_note);
        $this->assertStringContainsString('batas harian 2 resi otomatis tercapai', $state($c)->auto_shipment_note);
        $this->assertSame(AutoShipments::SKIPPED, $state($c)->auto_shipment_state);
    }

    public function test_a_provider_error_is_told_and_not_retried_endlessly(): void
    {
        $this->autoOn();
        $lead = $this->readyOrder();
        $this->orderError = 'receiver address is not valid';

        $this->runAuto();
        $this->runAuto(60);
        $this->runAuto(60);

        $this->assertCount(1, $this->callsTo('/shipment/v2/orders'), 'Tried once.');
        $lead = $this->inShop(fn () => $lead->fresh());
        $this->assertSame(AutoShipments::FAILED, $lead->auto_shipment_state);
        $this->assertStringContainsString('Everpro menolak permintaan: receiver address is not valid', $lead->auto_shipment_note);
        $admin = $this->adminOf($this->shop);
        $this->assertTrue($admin->notifications()->where('data->title', 'Resi gagal dibuat: Rina Kartika')->exists());

        $this->actingInTenant($admin, $this->shop);
        Livewire::test(ListShipments::class)->set('activeTab', 'action')->assertCanSeeTableRecords([$this->shipment()]);
    }

    public function test_the_morning_summary_and_the_daily_report_count_the_automatic_resi(): void
    {
        Storage::fake('local');
        $this->autoOn();
        $this->readyOrder();
        $this->runAuto();
        $admin = $this->adminOf($this->shop);

        // The next morning (today's went out already)
        $admin->notifications()->delete();
        $this->travelTo(Carbon::parse('2026-11-06 07:05', 'Asia/Jakarta'));
        $this->artisan('shipping:auto-resi')->assertSuccessful();
        $this->artisan('shipping:auto-resi')->assertSuccessful();

        $summary = $admin->notifications()->where('data->title', 'Ringkasan pengiriman')->get();
        $this->assertCount(1, $summary, 'Once a day.');
        $this->assertSame('1 resi otomatis kemarin, total COD Rp310.000 · 1 resi belum dicetak', $summary->first()->data['body']);

        $text = app(DailyReport::class)->build($this->shop, $admin)['text'];
        $this->assertStringContainsString("📦 *Pengiriman*\n• Resi otomatis: 1\n• Total COD resi otomatis: Rp", $text);
        $this->assertStringContainsString('• Resi belum dicetak (saat ini): 1', $text);
        $this->assertStringNotContainsString('Rina', $text);
    }

    // ---- Pengiriman menu -----------------------------------------------------------------------------------------

    public function test_print_all_makes_one_pdf_and_moves_the_parcels_to_printed_and_a_reprint_is_counted(): void
    {
        Storage::fake('local');
        $this->configure();
        $first = $this->book($this->order('cod', '6281234567821'));
        $this->travel(1)->minutes();
        $second = $this->book($this->order('cod', '6281234567822'));
        $admin = $this->adminOf($this->shop);
        $this->actingInTenant($admin, $this->shop);

        $this->assertNotNull($first->label_path, 'Kept when the resi came.');
        $labelCalls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'labels.example.com'))->count();

        Livewire::test(ListShipments::class)
            ->assertCanSeeTableRecords([$first, $second], inOrder: true)
            ->callAction('printAll', data: ['packing_list' => true])
            ->assertNotified('2 label dicetak');

        $this->assertSame($labelCalls, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'labels.example.com'))->count(), 'Printed from the kept files.');
        $batch = $this->inShop(fn () => LabelBatch::query()->sole());
        $this->assertSame([2, true, $admin->id], [$batch->count, $batch->packing_list, $batch->user_id]);
        $this->assertCount(3, self::pages(Storage::disk('local')->get($batch->path())), 'The packing list, then one page per label.');

        $printed = $this->inShop(fn () => Shipment::query()->orderBy('id')->get());
        $this->assertSame([1, 1], $printed->pluck('print_count')->all());
        $this->assertSame([$admin->id, $admin->id], $printed->pluck('printed_by')->all());
        Livewire::test(ListShipments::class)->assertCanNotSeeTableRecords([$first, $second])
            ->set('activeTab', 'printed')->assertCanSeeTableRecords([$first, $second])
            ->callTableAction('print', $first);
        $this->assertSame(2, $this->inShop(fn () => $first->fresh()->print_count), 'A reprint is counted.');
        $this->assertSame(2, $this->inShop(fn () => LabelBatch::query()->count()), 'Each print is in the history.');

        // By hand, back to Belum dicetak
        Livewire::test(ListShipments::class)->set('activeTab', 'printed')->callTableAction('markUnprinted', $second);
        $this->assertSame([$second->id], $this->inShop(fn () => Shipment::query()->tab('unprinted')->pluck('id')->all()));
    }

    public function test_a_label_that_does_not_come_needs_action_and_can_be_fetched_again(): void
    {
        Storage::fake('local');
        config(['carriers.label_tries' => 1]);
        $this->configure();
        $this->labelsDown = true;
        $shipment = $this->book($this->order());

        $this->assertNull($shipment->label_path);
        $this->assertNotNull($shipment->label_failed_at);
        $this->assertSame('Label belum tersedia', ShipmentResource::status($shipment));
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        Livewire::test(ListShipments::class)->assertCanNotSeeTableRecords([$shipment])
            ->set('activeTab', 'action')->assertCanSeeTableRecords([$shipment]);

        $this->labelsDown = false;
        Livewire::test(ListShipments::class)->set('activeTab', 'action')->callTableAction('retryLabel', $shipment)->assertNotified('Label tersimpan');
        $this->assertSame([$shipment->id], $this->inShop(fn () => Shipment::query()->tab('unprinted')->pluck('id')->all()));
    }

    public function test_a_status_callback_moves_the_parcel_between_tabs(): void
    {
        Storage::fake('local');
        $this->configure();
        $shipment = $this->book($this->order());
        $ids = fn (string $tab) => $this->inShop(fn () => Shipment::query()->tab($tab)->pluck('id')->all());

        $this->assertSame([$shipment->id], $ids('unprinted'));
        $this->courierSays('PICKED_UP');
        $this->assertSame([[], [$shipment->id]], [$ids('unprinted'), $ids('transit')]);
        $this->courierSays('DELIVERY_FAILED');
        $this->assertSame([$shipment->id], $ids('action'));
        $this->courierSays('DELIVERED');
        $this->assertSame([[], [$shipment->id]], [$ids('action'), $ids('done')]);
    }

    public function test_an_agent_sees_only_the_parcels_of_their_own_leads(): void
    {
        Storage::fake('local');
        $this->configure();
        $agent = $this->member($this->shop, Role::Agent);
        $mine = $this->book($this->order('cod', '6281234567831', ['owner_id' => $agent->id]));
        $other = $this->book($this->order('cod', '6281234567832', ['owner_id' => $this->adminOf($this->shop)->id]));
        $this->actingInTenant($agent, $this->shop);

        Livewire::test(ListShipments::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$other]);
        $this->assertSame('1', ShipmentResource::getNavigationBadge());
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
        $this->inShop(fn () => CarrierAccount::query()->firstOrFail()->forceFill(['settings' => ['sender_name' => 'Q', 'sender_phone' => '081300001111', 'sender_address' => 'Jl. Raya Kedungwaringin 1', 'origin_id' => 'SD-ORIGIN', 'origin_label' => 'Kedungwaringin, Kedungwaringin, Kab. Bekasi, Jawa Barat (17540)']])->save());
        $this->book($this->order());
        $before = count($this->sent());
        $this->courierSays('DELIVERED');
        $this->assertSame(['Terima kasih Rina -'], array_slice($this->sent(), $before));
    }

    public function test_the_grouped_rates_answer_is_read_by_courier_name_and_kind_of_service(): void
    {
        $this->configure();
        $lead = $this->order('transfer');

        $draft = $this->inShop(fn () => app(Shipments::class)->draft($lead));
        $this->assertTrue($draft->ok(), implode(' ', $draft->problems));

        // Every group is read; logistic_code is empty, so the courier is its logistic_name and "Regular" reads "REG".
        $this->assertSame(['Lion Parcel BOSSPACK', 'SiCepat REG', 'JNE REG', 'J&T Express REG', 'JNE Yakin Esok Sampai', 'JNE Trucking'],
            array_map(fn ($r) => $r->name(), $draft->rates), 'regular, express, then cargo; cheapest first in a group');
        $this->assertSame(['regular', 'regular', 'regular', 'regular', 'express', 'cargo'], array_map(fn ($r) => $r->category(), $draft->rates));

        // "JNE REG" (the lead's and the settings' courier) is JNE's Regular, whatever its code
        $this->assertSame(['REG23', 18000, 500], [$draft->rate->code, $draft->rate->price, $draft->rate->cashback()]);
        $this->assertTrue(collect($draft->rates)->firstWhere('code', 'REG')->matches('SiCepat REG'));
        $this->assertFalse(collect($draft->rates)->firstWhere('code', 'REG')->matches('JNE REG'));

        // After discount, plus the surcharge; numbers as strings are read
        $trucking = collect($draft->rates)->firstWhere('code', 'JTR23');
        $this->assertSame([42500, 40000, 45000], [$trucking->price, $trucking->meta['shipment_price'], $trucking->meta['price']]);
        $lion = collect($draft->rates)->firstWhere('code', 'BOSSPACK');
        $this->assertSame(14000, $lion->price, 'shipment_price_after_discount, not price');

        // The courier list: regular and express, cheapest first, cargo only over 5 kg
        $choices = $draft->choices();
        $this->assertSame(['Lion Parcel|BOSSPACK', 'SiCepat|REG', 'JNE|REG23', 'J&T Express|EZ', 'JNE|YES23'], array_keys($choices));
        $this->assertSame('JNE REG · Rp18.000 · cashback Rp500 · 2–3 hari', $choices['JNE|REG23']);
        $draft->weightGrams = 6000;
        $this->assertArrayHasKey('JNE|JTR23', $draft->choices());

        // Picking SiCepat by its key books SiCepat's own rate, not another courier's "REG"
        $picked = $this->inShop(fn () => app(Shipments::class)->draft($lead, ['rate_code' => 'SiCepat|REG']));
        $this->assertSame('SiCepat REG', $picked->rate->name());
        $this->inShop(fn () => app(Shipments::class)->book($lead, $picked));
        $order = $this->callsTo('/shipment/v2/orders')[0]['body'];
        $this->assertSame(['REG', 'sig-sicepat-reg', 16000, true, 1, 2], [$order['rate_code'], $order['signed_key'], $order['shipment_price'], $order['is_flat_rate'], $order['min_duration'], $order['max_duration']]);
    }

    public function test_cod_drops_some_services_and_the_cod_total_takes_the_shipping_promo(): void
    {
        $this->configure();
        // Never priced in the chat: Buat resi takes the product's shipping promo off Everpro's price.
        $this->inShop(fn () => $this->bag->update(['shipping_promos' => [['type' => 'discount', 'amount' => 5000, 'area' => 'java_bali']]]));
        $lead = $this->order('cod', extra: ['shipping_cost' => null]);

        $draft = $this->inShop(fn () => app(Shipments::class)->draft($lead));
        $this->assertTrue($draft->ok(), implode(' ', $draft->problems));
        $this->assertNull(collect($draft->rates)->firstWhere('code', 'BOSSPACK'), 'Lion BOSSPACK is not offered with COD');
        $this->assertSame('REG23', $draft->rate->code, 'JNE REG stays');

        $this->assertSame([18000, 13000, 313000], [$draft->rate->price, $draft->charged, $draft->codAmount]);
        $this->assertStringContainsString('potongan/subsidi penjual Rp5.000, Potongan ongkir Rp 5.000 (Tas Badminton, Jawa & Bali)', $draft->summary());

        // Everpro is paid its own price; the buyer pays the total after the promo.
        $this->inShop(fn () => app(Shipments::class)->book($lead, $draft));
        $order = $this->callsTo('/shipment/v2/orders')[0]['body'];
        $this->assertSame([18000, 313000], [$order['shipment_price'], $order['cod_price']]);
    }

    /** The body of the last notification titled $title shown to the signed-in agent. */
    private function notice(string $title): string
    {
        $notices = new Notifications;
        $notices->mount();

        return (string) $notices->notifications->last(fn ($n) => $n->getTitle() === $title)?->getBody();
    }

    /** The button the modal shows to book. */
    private function submit($page): Action
    {
        return $page->instance()->getMountedAction()->getModalSubmitAction();
    }

    public function test_the_buat_resi_button_is_the_modals_own_and_books_with_an_address_typed_in(): void
    {
        $this->configure();
        // COD saved on the lead, the address only as text: typed into the modal
        $lead = $this->order('cod', extra: ['shipping_address_parts' => null, 'shipping_address' => 'Jl. Mawar No. 5']);
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        $page = Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->mountAction('createShipment')
            ->setActionData(['addr_rt_rw' => '001/002', 'addr_kelurahan' => 'Sekeloa', 'addr_kecamatan' => 'Coblong', 'addr_kota' => 'Kota Bandung', 'addr_kode_pos' => '4013']);

        // Never the gray "Buat resi" link that opened the modal
        $this->assertSame('submit', $this->submit($page)->getName());
        $this->assertSame('primary', $this->submit($page)->getColor());
        $this->assertTrue($this->submit($page)->isDisabled());
        $page->callMountedAction();
        $this->assertStringContainsString('Kode pos harus 5 angka.', $this->notice('Resi belum bisa dibuat'));
        $this->assertSame([], $this->callsTo('/shipment/v2/orders'));

        // A field the browser let through but the form refuses: every error in a notification too
        $page->setActionData(['charged' => '-5'])->callMountedAction()->assertHasActionErrors(['charged']);
        $this->assertStringContainsStringIgnoringCase('ongkir ditagih ke pembeli', $this->notice('Periksa isian Buat resi'));

        $page->setActionData(['addr_kode_pos' => '40134', 'charged' => null]);
        $this->assertFalse($this->submit($page)->isDisabled());
        $page->callMountedAction()->assertHasNoActionErrors();

        $this->assertCount(1, $this->callsTo('/shipment/v2/orders'));
        $this->assertSame('40134', $this->callsTo('/shipment/v2/orders')[0]['body']['receiver_postal_code']);
        $this->assertSame(Shipment::SHIPPED, $this->shipment()->state);
    }

    public function test_the_agent_changes_the_shipping_and_cod_before_booking_and_an_old_shipping_cost_is_warned_about(): void
    {
        $this->configure();
        $this->inShop(fn () => $this->bag->update(['shipping_promos' => [['type' => 'discount', 'amount' => 5000, 'area' => 'all']]]));
        // Recorded before the promo existed
        $lead = $this->order('cod', extra: ['shipping_cost' => 45000]);
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        $draft = $this->inShop(fn () => app(Shipments::class)->draft($lead));
        $this->assertSame([45000, 13000, 345000], [$draft->charged, $draft->promoCharged, $draft->codAmount]);
        $this->assertSame(['Ongkir tercatat Rp45.000 berbeda dari hitungan promo Rp13.000 — pastikan sesuai kesepakatan.'], $draft->warnings());

        $edited = $this->inShop(fn () => app(Shipments::class)->draft($lead, ['edit' => ['charged' => 13000, 'cod_amount' => 300000]]));
        $this->assertSame([13000, 313000, 300000], [$edited->charged, $edited->calculatedCod, $edited->codAmount]);
        $this->assertSame(['Total COD Rp300.000 berbeda dari hitungan Rp313.000 (harga × jumlah + ongkir ditagih).'], $edited->warnings());

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->mountAction('createShipment')
            ->setActionData(['charged' => '13000', 'cod_amount' => '300000', 'weight_kg' => '2,5', 'note' => 'Pagar hijau', 'quantity' => '2', 'variant' => 'Merah'])
            ->callMountedAction()->assertHasNoActionErrors();

        $order = $this->callsTo('/shipment/v2/orders')[0]['body'];
        $this->assertSame([18000, 300000, 2.5, 'Pagar hijau'], [$order['shipment_price'], $order['cod_price'], $order['weight'], $order['receiver_address_note']]);
        $this->assertStringContainsString('Tas Badminton Merah x2', $order['package_desc']);

        $shipment = $this->shipment();
        $this->assertSame([13000, 300000, 2500], [$shipment->charged, $shipment->cod_amount, $shipment->weight_grams]);
        $lead = $this->inShop(fn () => $lead->fresh());
        $this->assertSame([13000, 300000, 'Merah'], [(int) $lead->shipping_cost, (int) $lead->deal_value, $lead->variant]);
        $this->assertSame('Merah', $lead->order_items[0]['variant']);
        $activity = $this->inShop(fn () => $lead->activities()->where('body', 'like', 'Rincian resi diubah agen:%')->sole());
        $this->assertStringContainsString('Ongkir ditagih Rp45.000 → Rp13.000', $activity->body);
        $this->assertStringContainsString('Total COD Rp313.000 → Rp300.000', $activity->body);
        $this->assertStringContainsString('Berat 2 kg → 2,5 kg', $activity->body);
        $this->assertStringContainsString('Varian Hitam → Merah', $activity->body);
    }

    public function test_everpro_gets_phone_numbers_starting_with_62_and_a_bad_number_is_stopped_before_calling_it(): void
    {
        foreach (['081234567890' => '6281234567890', '+62 812-3456-7890' => '6281234567890', '6281234567890' => '6281234567890', '81234567890' => '6281234567890', '0812345' => null, '12345678901' => null, '' => null] as $in => $out) {
            $this->assertSame($out, EverproCarrier::phone($in), $in);
        }

        $this->configure(settings: ['sender_phone' => '+62 813-0000-1111']);
        $this->book($this->order('cod', '6281234567890'));
        $order = $this->callsTo('/shipment/v2/orders')[0]['body'];
        $this->assertSame(['6281300001111', '6281234567890'], [$order['shipper_phone'], $order['receiver_phone']]);

        // Too short: said in the modal, Everpro never called
        $short = $this->order('cod', '6281234567891');
        $this->inShop(fn () => $short->forceFill(['phone' => '+620812345'])->save());
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        $calls = count($this->calls);
        $page = Livewire::test(EditLead::class, ['record' => $short->getRouteKey()])->mountAction('createShipment');
        $this->assertTrue($this->submit($page)->isDisabled());
        $page->callMountedAction();
        $this->assertStringContainsString('Nomor HP penerima tidak valid', $this->notice('Resi belum bisa dibuat'));
        $this->assertSame([], array_filter(array_slice($this->calls, $calls), fn ($c) => str_starts_with($c['path'], '/shipment/')), 'No rates, no order.');
    }

    public function test_a_refused_parcel_is_tried_again_with_its_edited_details_without_a_second_active_one(): void
    {
        Storage::fake('local');
        $this->configure();
        $lead = $this->order('cod');
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        $this->orderError = 'phone must starts with 62';

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->mountAction('createShipment')
            ->setActionData(['cod_amount' => '144000'])->callMountedAction()->assertHasNoActionErrors();
        $failed = $this->shipment();
        $this->assertSame([Shipment::FAILED, 144000], [$failed->state, $failed->cod_amount]);

        // From the lead and from the Pengiriman menu ("Perlu tindakan")
        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->assertActionVisible('retryShipment');
        Livewire::test(ListShipments::class)->set('activeTab', 'action')->assertTableActionVisible('retry', $failed);

        $this->orderError = null;
        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->callAction('retryShipment')->assertNotified('Resi JNE0001234 terbit.');

        $orders = $this->callsTo('/shipment/v2/orders');
        $this->assertCount(2, $orders);
        $this->assertSame([$failed->client_order_no, 144000], [$orders[1]['body']['client_order_no'], $orders[1]['body']['cod_price']], 'Same order number, the edited COD.');
        $this->assertSame(1, $this->inShop(fn () => Shipment::query()->count()));
        $this->assertSame(Shipment::SHIPPED, $this->shipment()->state);

        // Nothing to try again now; never a second active parcel
        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->assertActionHidden('retryShipment');
        $this->expectException(CarrierException::class);
        $this->inShop(fn () => app(Shipments::class)->retry($this->shipment()));
    }

    public function test_generate_sends_an_empty_object_and_a_late_tracking_number_is_waited_for(): void
    {
        Storage::fake('local');
        Queue::fake([FetchShipmentAwb::class]);
        $this->configure();
        $this->orderDataNull = true;
        $this->noAwb = true;
        $lead = $this->order('cod');

        $shipment = $this->book($lead);

        $generate = array_values(array_filter($this->calls, fn ($c) => str_ends_with($c['path'], '/generate')));
        $this->assertCount(1, $generate);
        $this->assertSame('{}', $generate[0]['raw'], 'An object, never [].');
        $this->assertSame([Shipment::ORDERED, 'TRD2026110500001', null], [$shipment->state, $shipment->provider_order_no, $shipment->error], '"data": null is not an error: the order is found in Everpro\'s list.');
        $this->assertSame('Menunggu nomor resi', ShipmentResource::status($shipment));
        $this->assertSame([$shipment->id], $this->inShop(fn () => Shipment::query()->tab('awaiting')->pluck('id')->all()));
        Queue::assertPushed(FetchShipmentAwb::class, fn ($job) => $job->shipmentId === $shipment->id && $job->delay !== null);

        // Two minutes later the number is there: only asked for, not generated again
        $this->noAwb = false;
        (new FetchShipmentAwb($shipment->id, $this->shop->id))->handle(app(CurrentTenant::class));

        $this->assertCount(1, array_filter($this->calls, fn ($c) => str_ends_with($c['path'], '/generate')));
        $shipment = $this->shipment();
        $this->assertSame([Shipment::SHIPPED, 'JNE0001234', 'TRD2026110500001', 'TRD2026110500001'], [$shipment->state, $shipment->awb, $shipment->provider_order_no, $shipment->order_id], 'Found in Everpro\'s list under its own number.');
        $this->assertNotNull($shipment->label_path, 'Label kept.');
        $this->assertSame([$shipment->id], $this->inShop(fn () => Shipment::query()->tab('unprinted')->pluck('id')->all()));
        $this->assertCount(1, array_filter($this->sent(), fn ($b) => str_contains((string) $b, 'Nomor resi: JNE0001234')), 'The buyer gets the number.');
    }

    public function test_a_tracking_number_still_missing_after_half_an_hour_needs_action(): void
    {
        $this->configure();
        $this->noAwb = true;
        $shipment = $this->book($this->order('cod'));

        $this->assertSame(FetchShipmentAwb::TRIES + 1, count($this->callsTo('/shipment/v1/orders')), 'Looked up every 2 minutes, 15 times, then stopped.');
        $shipment = $this->shipment();
        $this->assertSame(Shipment::ORDERED, $shipment->state);
        $this->assertStringContainsString('Nomor resi belum terbit setelah 30 menit', $shipment->error);
        $this->assertSame([$shipment->id], $this->inShop(fn () => Shipment::query()->tab('action')->pluck('id')->all()));
        $this->assertTrue($shipment->retryable());
    }

    public function test_coba_lagi_on_a_booked_parcel_without_a_number_never_books_again(): void
    {
        Storage::fake('local');
        $this->configure();
        $lead = $this->order('cod');
        $this->generateError = 'code=400, message=Unmarshal type error: expected=request.GenerateAwbRequest, got=array';
        $this->noAwb = true;
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        $shipment = $this->book($lead);
        $this->assertSame(Shipment::ORDERED, $shipment->state);
        $this->assertNotNull($shipment->error);
        $this->assertSame([$shipment->id], $this->inShop(fn () => Shipment::query()->tab('action')->pluck('id')->all()));

        $this->generateError = null;
        $this->noAwb = false;
        Livewire::test(ListShipments::class)->set('activeTab', 'action')->callTableAction('retry', $shipment)->assertNotified('Resi JNE0001234 terbit.');

        $this->assertCount(1, $this->callsTo('/shipment/v2/orders'), 'No second order at Everpro.');
        $this->assertSame([Shipment::SHIPPED, 'JNE0001234'], [$this->shipment()->state, $this->shipment()->awb]);
        $this->assertSame(1, $this->inShop(fn () => Shipment::query()->count()));
    }

    public function test_buat_resi_never_assumes_transfer_when_the_way_of_paying_is_unknown(): void
    {
        $this->configure();
        $lead = $this->order('cod', extra: ['payment_type' => null]);
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        $draft = $this->inShop(fn () => app(Shipments::class)->draft($lead));
        $this->assertFalse($draft->ok());
        $this->assertContains('Cara bayar belum dipastikan: pilih COD atau Transfer (sudah lunas).', $draft->problems);
        $this->assertSame([], $this->callsTo('/shipment/v2/rates'), 'Not priced as transfer behind the agent\'s back.');

        // Not without picking
        $page = Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->mountAction('createShipment');
        $this->assertTrue($this->submit($page)->isDisabled());
        $page->callMountedAction();
        $this->assertStringContainsString('Pilih cara bayar dulu (COD atau Transfer).', $this->notice('Resi belum bisa dibuat'));
        $this->assertSame([], $this->callsTo('/shipment/v2/orders'));

        // Picked COD: saved to the lead as confirmed, and booked as COD
        $page->setActionData(['payment' => 'cod'])->callMountedAction()->assertHasNoActionErrors();
        $lead = $this->inShop(fn () => $lead->fresh());
        $this->assertSame(['cod', null], [$lead->payment_type, $lead->payment_type_source]);
        $order = $this->callsTo('/shipment/v2/orders')[0]['body'];
        $this->assertTrue($order['is_cod']);
        $this->assertSame(310000, $order['cod_price']);
        $this->assertTrue($this->inShop(fn () => $lead->activities()->where('body', 'like', 'Cara bayar dipastikan%')->exists()));
    }

    public function test_cod_written_in_the_chat_is_suggested_and_still_confirmed_by_the_agent(): void
    {
        $this->configure();
        $lead = $this->order('cod', extra: ['payment_type' => null]);

        foreach (['C0D aja kak' => 'cod', 'kak saya pakai cod ya, nanti dibayar pas paketnya sampai di rumah' => 'cod', 'tf aja min' => 'transfer'] as $text => $expected) {
            $this->inShop(fn () => $lead->fresh()->forceFill(['payment_type' => null, 'payment_type_source' => null])->save());
            $this->inbound($text);
            $fresh = $this->inShop(fn () => $lead->fresh());
            $this->assertSame([$expected, 'chat'], [$fresh->payment_type, $fresh->payment_type_source], $text);
        }

        $this->inbound('COD min');
        $lead = $this->inShop(fn () => $lead->fresh());
        $this->assertSame('cod', $this->inShop(fn () => app(Shipments::class)->paymentSuggestion($lead)));
        $this->assertNull(Shipments::payment($lead), 'Read from the chat: not trusted for Buat resi');
        $this->assertFalse($this->inShop(fn () => app(Shipments::class)->draft($lead))->ok());
        $this->assertTrue($this->inShop(fn () => app(Shipments::class)->draft($lead, ['payment' => 'cod']))->ok());

        // A blank lead: the suggestion is read from its messages without saving
        $this->inShop(fn () => $lead->forceFill(['payment_type' => null, 'payment_type_source' => null])->save());
        $this->assertSame('cod', $this->inShop(fn () => app(Shipments::class)->paymentSuggestion($lead->fresh())));
        $this->assertNull($this->inShop(fn () => $lead->fresh()->payment_type));

        // An agent's choice is not undone by re-reading old messages
        $this->inShop(fn () => $lead->fresh()->forceFill(['payment_type' => 'transfer', 'paid_at' => now()])->save());
        $this->inShop(fn () => app(ChatShippingDetails::class)->fromConversation($lead->fresh()));
        $this->assertSame(['transfer', null], $this->inShop(fn () => [$lead->fresh()->payment_type, $lead->fresh()->payment_type_source]));
    }

    public function test_a_transfer_nobody_has_seen_is_warned_about_and_checked_first(): void
    {
        $this->configure();
        $lead = $this->order('transfer');
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        $draft = $this->inShop(fn () => app(Shipments::class)->draft($lead));
        $this->assertTrue($draft->ok());
        $this->assertTrue($draft->unpaid);
        $this->assertStringContainsString('⚠ Pembayaran transfer belum dikonfirmasi.', $draft->summary());

        $page = Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])->mountAction('createShipment');
        $this->assertTrue($this->submit($page)->isDisabled());
        $page->callMountedAction();
        $this->assertStringContainsString('Saya sudah memastikan transfer pembeli masuk', $this->notice('Resi belum bisa dibuat'));
        $this->assertSame([], $this->callsTo('/shipment/v2/orders'));
        $page->setActionData(['transfer_checked' => true])->callMountedAction()->assertHasNoActionErrors();
        $this->assertFalse($this->callsTo('/shipment/v2/orders')[0]['body']['is_cod']);

        // Paid (or a proof came in): no warning
        $paid = $this->order('transfer', '6281234567899', ['paid_at' => now()]);
        $this->assertFalse($this->inShop(fn () => app(Shipments::class)->draft($paid))->unpaid);
        $this->assertStringContainsString('Bukan COD: pembeli sudah bayar di muka.', $this->inShop(fn () => app(Shipments::class)->draft($paid))->summary());
    }

    // ---- Balance ("Menunggu saldo") ------------------------------------------------------------------------------

    public function test_a_prepaid_parcel_above_the_balance_waits_without_an_order_and_is_sent_once_the_balance_is_enough(): void
    {
        $this->autoOn();
        $this->balanceAnswer = ['cash' => 10000, 'name' => 'Quicker Sport'];
        $lead = $this->readyOrder('transfer', extra: ['paid_at' => now()]);

        $this->runAuto();

        $shipment = $this->shipment();
        $this->assertSame([], $this->callsTo('/shipment/v2/orders'), 'No order at Everpro, so none is left orphaned.');
        $this->assertSame(Shipment::PENDING, $shipment->state);
        $this->assertTrue($shipment->waitingBalance());
        $this->assertSame('Saldo Everpro tidak cukup (perlu Rp 18.000, saldo Rp 10.000). Isi saldo lalu tekan Coba lagi.', $shipment->error);
        $this->assertSame([$shipment->id], $this->inShop(fn () => Shipment::query()->tab('balance')->pluck('id')->all()));
        $this->assertSame([], $this->inShop(fn () => Shipment::query()->tab('action')->pluck('id')->all()), 'Not "Perlu tindakan": it waits by itself.');
        $this->assertSame(AutoShipments::WAITING_BALANCE, $this->inShop(fn () => $lead->fresh()->auto_shipment_state));

        // The admins are told once: bell and email; and the daily report says so.
        $admin = $this->adminOf($this->shop);
        $this->assertSame(1, $admin->notifications()->where('data->title', 'Resi otomatis menunggu saldo Everpro: Rina Kartika')->count());
        Mail::assertSent(ShipmentWaitsForBalance::class, fn ($m) => $m->hasTo($admin->email) && str_contains($m->detail, 'perlu Rp 18.000, saldo Rp 10.000'));
        $text = app(DailyReport::class)->build($this->shop, $admin)['text'];
        $this->assertStringContainsString('• Menunggu saldo Everpro (saat ini): 1', $text);
        $this->assertStringContainsString('Saldo Everpro rendah: *Rp 10.000*', $text);

        // The warning in the Pengiriman menu and the settings
        $this->actingInTenant($admin, $this->shop);
        Livewire::test(ListShipments::class)->assertSee('Saldo Everpro rendah')->assertSee('1 kiriman menunggu saldo')
            ->set('activeTab', 'balance')->assertCanSeeTableRecords([$shipment])->assertSee('Menunggu saldo');
        Livewire::test(ShippingSettingsPage::class)->assertSee('Saldo Everpro rendah')->assertSet('carrier.low_balance', 100000);

        // Still short ten minutes later: nothing is sent, nobody is told again.
        $this->travel(10)->minutes();
        $this->artisan('shipping:balance-wait')->assertSuccessful();
        $this->assertSame([], $this->callsTo('/shipment/v2/orders'));
        $this->assertSame(1, $admin->notifications()->where('data->title', 'Resi otomatis menunggu saldo Everpro: Rina Kartika')->count());

        // Topped up: booked once, the tracking number comes, and the next check does nothing.
        $this->balanceAnswer = ['cash' => 500000, 'name' => 'Quicker Sport'];
        $this->travel(10)->minutes();
        $this->artisan('shipping:balance-wait')->assertSuccessful();
        $this->artisan('shipping:balance-wait')->assertSuccessful();

        $this->assertCount(1, $this->callsTo('/shipment/v2/orders'));
        $this->assertSame([Shipment::SHIPPED, 'JNE0001234', null], [$this->shipment()->state, $this->shipment()->awb, $this->shipment()->balance_wait_at]);
        $this->assertSame(AutoShipments::DONE, $this->inShop(fn () => $lead->fresh()->auto_shipment_state));
        $this->assertSame(1, $this->inShop(fn () => Shipment::query()->count()));
    }

    public function test_lead_466_a_refused_generate_keeps_everpros_real_reason_and_coba_lagi_never_books_again(): void
    {
        Storage::fake('local');
        $this->configure();
        $this->orderDataNull = true;
        $this->noAwb = true;
        $this->awbMissing = true;
        $this->generateError = 'insufficient balance, please top up balance first';
        $this->generateStatus = 406;
        // Enough before booking (the ongkir of this order is Rp 18.000), then short when the number is generated.
        $this->balances = [750000, 10000];
        $lead = $this->order('transfer', extra: ['paid_at' => now()]);

        $shipment = $this->book($lead);

        $this->assertCount(1, $this->callsTo('/shipment/v2/orders'));
        $this->assertSame(Shipment::ORDERED, $shipment->state);
        $this->assertTrue($shipment->waitingBalance());
        $this->assertSame('Saldo Everpro tidak cukup (perlu Rp 18.000, saldo Rp 10.000). Isi saldo lalu tekan Coba lagi.', $shipment->error);
        $this->assertStringNotContainsString('awb number not found', $shipment->error, 'The 404 of the lookup after a refused generate never hides the reason.');
        $this->assertSame(['message' => 'insufficient balance, please top up balance first', 'status' => 406], array_intersect_key((array) data_get($shipment->request, 'provider_error'), ['message' => 1, 'status' => 1]));
        $note = $this->inShop(fn () => $lead->activities()->where('body', 'like', '%menunggu saldo%')->value('body'));
        $this->assertStringContainsString('"insufficient balance, please top up balance first" (406)', $note);
        $this->assertStringNotContainsString('awb number not found', $note);
        $this->assertTrue($this->adminOf($this->shop)->notifications()->where('data->title', 'Kiriman menunggu saldo Everpro: Rina Kartika')->exists());

        // The ten-minute repair job no longer asks Everpro again and again.
        $generates = fn () => count(array_filter($this->calls, fn ($c) => str_ends_with($c['path'], '/generate')));
        $before = $generates();
        $this->artisan('shipping:fix-everpro-orders --waiting --apply')->assertSuccessful();
        $this->assertSame($before, $generates());

        // Coba lagi while the balance is still short: told so, nothing is sent.
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        $this->balanceAnswer = ['cash' => 10000, 'name' => 'Quicker Sport'];
        Livewire::test(ListShipments::class)->set('activeTab', 'balance')->callTableAction('retry', $shipment)->assertNotified('Kiriman belum bisa dicoba lagi');
        $this->assertSame($before, $generates());

        // Topped up: Coba lagi only generates the number of the order Everpro already has.
        $this->balanceAnswer = ['cash' => 500000, 'name' => 'Quicker Sport'];
        $this->generateError = null;
        $this->noAwb = false;
        $this->awbMissing = false;
        Livewire::test(ListShipments::class)->set('activeTab', 'balance')->callTableAction('retry', $shipment)->assertNotified('Resi JNE0001234 terbit.');

        $this->assertCount(1, $this->callsTo('/shipment/v2/orders'), 'No new order at Everpro.');
        $this->assertSame([Shipment::SHIPPED, 'JNE0001234', null], [$this->shipment()->state, $this->shipment()->awb, $this->shipment()->balance_wait_at]);
        $this->assertSame(1, $this->inShop(fn () => Shipment::query()->count()));
    }

    public function test_cod_parcels_are_not_held_by_the_balance_check_and_the_low_balance_level_is_the_agencys(): void
    {
        $this->configure(settings: ['low_balance' => 5000]);
        $this->balanceAnswer = ['cash' => 10000, 'name' => 'Quicker Sport'];

        $shipment = $this->book($this->order('cod'));

        $this->assertSame([], $this->callsTo('/balance/v1/detail'), 'COD is not paid from the balance.');
        $this->assertSame(Shipment::SHIPPED, $shipment->state);

        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        $this->inShop(fn () => CarrierAccount::query()->firstOrFail()->forceFill(['balance' => 10000, 'balance_at' => now()])->save());
        Livewire::test(ListShipments::class)->assertDontSee('Saldo Everpro rendah');
    }

    // ---- Tracking after a gap (AWB as string, one refused number, catch-up messages) -----------------------------

    /** A second booked parcel for another buyer, with its tracking number set to $awb. */
    private function parcelWith(string $awb, string $phone = '6281234567890'): Shipment
    {
        $shipment = $this->book($this->order('cod', $phone));
        $this->inShop(fn () => $shipment->forceFill(['awb' => $awb])->save());

        return $shipment->fresh();
    }

    private function row(string $awb, ?string $code, array $history = [], mixed $statusId = null): array
    {
        return ['awb_number' => $awb, 'tracking_code' => $code, 'status_id' => $statusId, 'status' => null, 'tracking_history' => $history];
    }

    public function test_a_digits_only_jne_tracking_number_is_sent_to_everpro_as_a_string(): void
    {
        $this->configure();
        $shipment = $this->parcelWith('6329732600177801');
        $this->trackByAwb['6329732600177801'] = $this->row('6329732600177801', 'ARRIVED AT SORTING HUB', [
            ['date' => '2026-11-05T01:00:00Z', 'tracking_code' => 'ARRIVED AT SORTING HUB', 'description' => 'Tiba di hub sortir'],
        ], 400);

        $this->artisan('shipping:track')->assertSuccessful();

        $call = collect($this->callsTo('/shipment/v1/orders/track'))->last();
        $this->assertStringContainsString('"awb_numbers":["6329732600177801"]', $call['raw']);
        $fresh = $this->inShop(fn () => $shipment->fresh());
        $this->assertSame(ShipmentStatus::IN_TRANSIT, $fresh->status);
        $this->assertSame('ARRIVED AT SORTING HUB', $fresh->raw_status);
        $this->assertNotNull($fresh->tracked_at);
    }

    public function test_one_refused_tracking_number_does_not_stop_the_others(): void
    {
        $this->configure();
        $good = $this->parcelWith('6329732600177801');
        $bad = $this->parcelWith('JNEBAD000001', '6281234567892');
        $this->rejectAwbs = ['JNEBAD000001'];
        $this->trackByAwb['6329732600177801'] = $this->row('6329732600177801', 'IN PROCESS');
        Log::spy();

        $this->artisan('shipping:track --now')->expectsOutputToContain('1 kiriman diperbarui, ditolak penyedia: JNEBAD000001')->assertSuccessful();

        $this->assertSame(ShipmentStatus::IN_TRANSIT, $this->inShop(fn () => $good->fresh()->status));
        $this->assertSame(ShipmentStatus::AWB_CREATED, $this->inShop(fn () => $bad->fresh()->status));
        // The batch, then each number on its own.
        $this->assertCount(3, $this->callsTo('/shipment/v1/orders/track'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c = []) => $m === 'Everpro refused tracking numbers' && $c['awbs'] === ['JNEBAD000001'])->once();
    }

    public function test_a_catch_up_after_a_gap_tells_the_buyer_only_the_newest_status(): void
    {
        $this->configure();
        $shipment = $this->parcelWith('6329732600177801');
        $before = count($this->sent());
        // 03:00Z now: picked up, on the way and out for delivery all came in one answer.
        $this->trackByAwb['6329732600177801'] = $this->row('6329732600177801', 'ONGOING_TO_RECEIVER', [
            ['date' => '2026-11-04T09:00:00Z', 'tracking_code' => 'PICKED_UP', 'description' => 'Diambil kurir'],
            ['date' => '2026-11-04T20:00:00Z', 'tracking_code' => 'ARRIVED AT SORTING HUB', 'description' => 'Hub sortir'],
            ['date' => '2026-11-05T01:00:00Z', 'tracking_code' => 'ONGOING_TO_RECEIVER', 'description' => 'Diantar kurir'],
        ]);

        $this->artisan('shipping:track')->assertSuccessful();

        $messages = array_slice($this->sent(), $before);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('sedang diantar kurir', $messages[0]);
        $this->assertSame(['out_for_delivery'], $this->inShop(fn () => ShipmentNotice::query()->pluck('status')->all()));
        $this->assertCount(4, $this->inShop(fn () => $shipment->fresh()->events), 'Resi terbit and the three steps.');

        // Tracked again: once per status per parcel.
        $this->artisan('shipping:track')->assertSuccessful();
        $this->assertCount(1, array_slice($this->sent(), $before));
    }

    public function test_a_status_older_than_24_hours_is_not_told_but_an_open_failed_delivery_is(): void
    {
        $this->configure();
        $old = $this->parcelWith('6329732600177801');
        $failed = $this->parcelWith('6329732600177802', '6281234567892');
        $before = count($this->sent());
        $this->trackByAwb = [
            '6329732600177801' => $this->row('6329732600177801', 'DELIVERED', [
                ['date' => '2026-11-03T02:00:00Z', 'tracking_code' => 'DELIVERED', 'description' => 'Diterima oleh RINA'],
            ]),
            '6329732600177802' => $this->row('6329732600177802', 'FAILED', [
                ['date' => '2026-11-03T05:00:00Z', 'tracking_code' => 'FAILED', 'description' => 'Rumah kosong'],
            ]),
        ];

        $this->artisan('shipping:track')->assertSuccessful();

        $messages = array_slice($this->sent(), $before);
        $this->assertCount(1, $messages, implode(' | ', $messages));
        $this->assertStringContainsString('kurir belum berhasil mengantar', $messages[0]);
        $this->assertSame(ShipmentStatus::DELIVERED, $this->inShop(fn () => $old->fresh()->status));
        $this->assertSame('Selesai', $this->inShop(fn () => $old->lead->fresh('stage')->stage->name), 'The stage still moves.');
        $this->assertTrue($this->inShop(fn () => $old->lead->activities()->where('body', 'like', '%tidak dikirim ke pembeli: perubahannya sudah lebih dari 24 jam%')->exists()));
    }

    public function test_everpro_tracking_codes_seen_in_production_map_to_our_statuses(): void
    {
        foreach (['ARRIVED AT SORTING HUB', 'IN PROCESS', 'RECEIVED AT SORTING CENTER', 'DEPART FROM TRANSIT'] as $code) {
            $this->assertSame(ShipmentStatus::IN_TRANSIT, ShipmentStatus::normalize('everpro', $code), $code);
        }

        $this->assertSame(ShipmentStatus::AWB_CREATED, ShipmentStatus::normalize('everpro', 'WAITING FOR PICK UP'));
        $this->assertSame(ShipmentStatus::PICKUP_FAILED, ShipmentStatus::normalize('everpro', 'FAILED TO PICKUP'));
        $this->assertSame('Gagal dijemput', ShipmentStatus::label(ShipmentStatus::PICKUP_FAILED));
        // A new pickup after a failed one goes back to waiting.
        $this->assertTrue(ShipmentStatus::advances(ShipmentStatus::PICKUP_FAILED, ShipmentStatus::AWB_CREATED));
    }

    public function test_a_failed_pickup_needs_action_and_tells_staff_not_the_buyer(): void
    {
        $this->configure();
        $shipment = $this->parcelWith('6329732600177801');
        $before = count($this->sent());
        $this->trackByAwb['6329732600177801'] = $this->row('6329732600177801', 'FAILED TO PICKUP');

        $this->artisan('shipping:track')->assertSuccessful();

        $fresh = $this->inShop(fn () => $shipment->fresh());
        $this->assertSame(ShipmentStatus::PICKUP_FAILED, $fresh->status);
        $this->assertTrue($fresh->needsAction());
        $this->assertTrue($fresh->cancellable());
        $this->assertSame([$fresh->id], $this->inShop(fn () => Shipment::query()->tab('action')->pluck('id')->all()));
        $this->assertSame([], $this->inShop(fn () => Shipment::query()->tab('printed')->pluck('id')->all()));
        $this->assertCount(0, array_slice($this->sent(), $before));
        Mail::assertSent(ShipmentNeedsAction::class);
    }

    public function test_a_single_tracking_that_lags_at_waiting_for_pickup_is_checked_with_the_bulk_tracking(): void
    {
        $this->configure();
        $lead = $this->packed();
        $this->track[] = $this->row('6329802600008692', 'WAITING FOR PICK UP');
        [$shipment] = $this->link($lead, '6329802600008692');
        $this->assertSame(ShipmentStatus::AWB_CREATED, $shipment->status);

        // GET still says waiting; POST /orders/track knows it is on its way.
        $this->track[] = $this->row('6329802600008692', 'WAITING FOR PICK UP');
        $this->trackByAwb['6329802600008692'] = $this->row('6329802600008692', 'ARRIVED AT SORTING HUB', [], 400);
        $this->artisan('shipping:track')->assertSuccessful();

        $this->assertSame(ShipmentStatus::IN_TRANSIT, $this->inShop(fn () => $shipment->fresh()->status));
        $this->assertStringContainsString('"awb_numbers":["6329802600008692"]', collect($this->callsTo('/shipment/v1/orders/track'))->last()['raw']);
    }

    public function test_parcels_not_refreshed_for_six_hours_are_flagged_red(): void
    {
        $this->configure();
        $shipment = $this->parcelWith('6329732600177801');
        $this->inShop(fn () => $shipment->forceFill(['tracked_at' => now()->subHours(7)])->save());

        $fresh = $this->inShop(fn () => $shipment->fresh());
        $this->assertTrue($fresh->trackingStale());
        $this->actingAs($this->adminOf($this->shop));
        $this->inShop(function () {
            $this->assertStringContainsString('1 kiriman belum diperbarui lebih dari 6 jam', (string) ShipmentResource::trackingWarning());
        });

        $this->trackByAwb['6329732600177801'] = $this->row('6329732600177801', 'IN PROCESS');
        $this->artisan('shipping:track --now')->assertSuccessful();

        $this->assertFalse($this->inShop(fn () => $shipment->fresh()->trackingStale()));
        $this->assertNull($this->inShop(fn () => ShipmentResource::trackingWarning()));
    }
}
