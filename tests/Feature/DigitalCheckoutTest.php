<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Ai\DigitalTools;
use App\Automation\DigitalStages;
use App\Billing\Ipaymu\CallbackSignature;
use App\Billing\Ipaymu\IpaymuClient;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\PaymentGatewayPage;
use App\Mail\DigitalAccessReady;
use App\Models\DigitalPayment;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadForm;
use App\Models\PaymentGateway;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Payments\DigitalCheckout;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Tahap 3: a digital agency's own iPaymu account invoices a confirmed purchase and marks it paid only when iPaymu says so. */
class DigitalCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const VA = '0000005099000001';

    private const KEY = 'SANDBOX-TEST-KEY-DIGITAL';

    private Tenant $digital;

    private Property $ebook;

    /** @var list<array> Messages API responses, served in order */
    private array $ai = [];

    /** @var array<string, mixed> what iPaymu's check transaction answers */
    private array $transaction = [];

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->digital = app(ProvisionTenant::class)('Kelas Pintar', 'kelas-pintar', User::factory()->create(), 'digital');
        $this->ebook = $this->in(fn () => Property::create(['name' => 'E-book Resep Harian', 'price_from' => 49000, 'access_url' => 'https://example.com/unduh/resep']));
        $this->in(fn () => PaymentGateway::create(['va' => self::VA, 'api_key' => self::KEY, 'sandbox' => true, 'active' => true, 'invoice_hours' => 24, 'access_days' => 7]));

        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false,
            'services.ipaymu.va' => '0000001111111111', 'services.ipaymu.api_key' => 'JITU-SUBSCRIPTION-KEY']);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => fn () => Http::response(array_shift($this->ai) ?? ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Baik, Kak.']]]),
            'gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n]),
            'sandbox.ipaymu.com/api/v2/payment' => fn () => Http::response(['Status' => 200, 'Message' => 'success', 'Data' => ['SessionID' => 'SESS-'.++$this->n, 'Url' => 'https://sandbox.ipaymu.com/payment/SESS-'.$this->n]]),
            'sandbox.ipaymu.com/api/v2/transaction' => fn () => Http::response(['Status' => 200, 'Message' => 'success', 'Data' => $this->transaction]),
        ]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function in(callable $fn, ?Tenant $tenant = null): mixed
    {
        return app(CurrentTenant::class)->run($tenant ?? $this->digital, $fn);
    }

    private function channel(): WaChannel
    {
        return $this->in(fn () => WaChannel::query()->first() ?? WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+628990000002', 'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Auto,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function inbound(string $text): WaMessage
    {
        $channel = $this->channel();

        return $this->in(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6289900001234'), 'text', $text, null, CarbonImmutable::now(), 'Dina',
        )));
    }

    private function lead(): Lead
    {
        return $this->in(fn () => Lead::query()->with('stage')->firstOrFail());
    }

    private function sent(): array
    {
        return $this->in(fn () => WaMessage::query()->where('direction', 'out')->orderBy('id')->pluck('body')->all());
    }

    /** A buyer who confirmed two e-books in the chat: the AI recorded the purchase, the system sent the link. */
    private function confirmedPurchase(): DigitalPayment
    {
        $this->ai = [
            ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => DigitalTools::RECORD,
                'input' => ['items' => [['product_id' => $this->ebook->id, 'jumlah' => 2]], 'status' => 'konfirmasi', 'email' => 'dina@example.com']]]],
            ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Siap Kak, totalnya Rp 98.000. Tautan bayar menyusul ya.']]],
        ];
        $this->inbound('Jadi beli 2 kak, email dina@example.com');

        return $this->in(fn () => DigitalPayment::query()->latest('id')->firstOrFail());
    }

    private function payload(DigitalPayment $payment, array $over = []): array
    {
        return $over + ['trx_id' => '4242', 'status' => 'berhasil', 'status_code' => '1', 'sid' => $payment->session_id, 'reference_id' => $payment->reference_id,
            'amount' => (string) $payment->amount, 'via' => 'qris', 'is_escrow' => 'false', 'additional_info' => '[]', 'transaction_status_code' => '1'];
    }

    private function notify(array $payload, string $va = self::VA, ?int $tenant = null)
    {
        $signature = hash_hmac('sha256', CallbackSignature::candidates($payload)->current(), $va);

        return $this->call('POST', '/digital/ipaymu/notify/'.($tenant ?? $this->digital->id), [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_X_SIGNATURE' => $signature,
        ], http_build_query($payload));
    }

    private function paidTransaction(DigitalPayment $payment, array $over = []): void
    {
        $this->transaction = $over + ['TransactionId' => 4242, 'Status' => 1, 'StatusDesc' => 'Berhasil', 'ReferenceId' => $payment->reference_id,
            'SessionId' => $payment->session_id, 'Amount' => $payment->amount + 4000, 'SubTotal' => $payment->amount, 'Fee' => 1500];
    }

    // ---- Invoice ------------------------------------------------------------------------------------------------

    public function test_a_confirmed_purchase_gets_an_ipaymu_link_signed_with_the_agencys_own_account(): void
    {
        $payment = $this->confirmedPurchase();

        $lead = $this->lead();
        $this->assertSame('Menunggu pembayaran', $lead->stage->name);
        $this->assertSame('other', $lead->payment_type);
        $this->assertSame(98000, (int) $payment->amount);
        $this->assertSame(98000, (int) $lead->payment_amount);
        $this->assertSame(DigitalPayment::PENDING, $payment->status);
        $this->assertTrue($payment->expires_at->between(now()->addHours(23), now()->addHours(25)));

        $request = Http::recorded(fn (Request $r) => str_contains($r->url(), 'ipaymu.com/api/v2/payment'))->first()[0];
        $this->assertSame(self::VA, $request->header('va')[0], 'the agency account, not JITU\'s subscription account');
        $body = json_decode($request->body(), true);
        $this->assertSame(['E-book Resep Harian'], $body['product']);
        $this->assertSame(['2'], $body['qty']);
        $this->assertSame(['49000'], $body['price']);
        $this->assertStringEndsWith('/digital/ipaymu/notify/'.$this->digital->id, $body['notifyUrl']);
        $this->assertStringContainsString('/bayar/'.$payment->token, $body['returnUrl']);

        $link = collect($this->sent())->last();
        $this->assertStringContainsString($payment->payment_url, $link);
        $this->assertStringContainsString('Rp 98.000', $link);
        $this->assertNull($lead->paid_at);
        $this->assertTrue($this->in(fn () => $lead->activities()->where('type', 'payment_link')->where('body', 'like', 'Tautan bayar iPaymu dikirim sistem%')->exists()));
    }

    public function test_the_open_link_is_reused_and_invoices_are_limited_per_day(): void
    {
        $payment = $this->confirmedPurchase();
        $lead = $this->lead();
        $admin = $this->adminOf($this->digital);

        $again = $this->in(fn () => app(DigitalCheckout::class)->start($lead, 98000, $admin));
        $this->assertSame($payment->payment_url, $again['url']);
        $this->assertSame(1, $this->in(fn () => DigitalPayment::query()->count()));

        for ($i = 2; $i <= DigitalCheckout::MAX_INVOICES_PER_DAY; $i++) {
            $this->in(fn () => app(DigitalCheckout::class)->start($lead, 98000 + $i, $admin, send: false));
        }
        $this->assertSame('batas_percobaan', $this->in(fn () => app(DigitalCheckout::class)->start($lead, 1000, $admin))['status']);
    }

    // ---- Callback -----------------------------------------------------------------------------------------------

    public function test_a_verified_callback_marks_the_purchase_paid_once_and_sends_a_signed_access_link(): void
    {
        $payment = $this->confirmedPurchase();
        $this->paidTransaction($payment);

        $this->notify($this->payload($payment))->assertOk();

        $lead = $this->lead();
        $this->assertSame('Lunas & akses terkirim', $lead->stage->name);
        $this->assertNotNull($lead->paid_at);
        $this->assertSame(98000, (int) $lead->deal_value);
        $this->assertSame(DigitalPayment::PAID, $payment->refresh()->status);
        $this->assertSame(1500, (int) $payment->fee);
        $access = collect($this->sent())->last();
        $this->assertStringContainsString('/akses/'.$payment->token, $access);
        $this->assertStringContainsString('signature=', $access);
        $this->assertStringNotContainsString('https://example.com/unduh/resep', $access, 'the real product link is never sent');
        Mail::assertSent(DigitalAccessReady::class, fn ($mail) => $mail->hasTo('dina@example.com'));

        // iPaymu repeats the callback: nothing is paid or sent twice.
        $count = count($this->sent());
        $this->notify($this->payload($payment))->assertOk();
        $this->assertCount($count, $this->sent());
        $this->assertSame(1, $this->in(fn () => LeadActivity::query()->where('type', 'payment_received')->count()));
    }

    public function test_a_callback_is_refused_without_the_agencys_signature_or_with_other_data(): void
    {
        $payment = $this->confirmedPurchase();
        $this->paidTransaction($payment);

        // Signed with JITU's subscription VA, or sent to another agency's address.
        $this->notify($this->payload($payment), '0000001111111111')->assertStatus(400);
        $shop = app(ProvisionTenant::class)('Toko Contoh', 'toko-contoh', User::factory()->create(), 'retail');
        $this->notify($this->payload($payment), self::VA, $shop->id)->assertStatus(400);

        // iPaymu's own record says another amount or reference.
        $this->paidTransaction($payment, ['SubTotal' => 1000]);
        $this->notify($this->payload($payment))->assertStatus(422);
        $this->paidTransaction($payment, ['ReferenceId' => 'D0-0-other']);
        $this->notify($this->payload($payment))->assertStatus(422);

        // Pending at iPaymu: stays pending even though the callback says "berhasil".
        $this->paidTransaction($payment, ['Status' => 0]);
        $this->notify($this->payload($payment))->assertOk();

        $this->assertNull($this->lead()->paid_at);
        $this->assertSame('Menunggu pembayaran', $this->lead()->stage->name);
        $this->assertSame(DigitalPayment::PENDING, $payment->refresh()->status);
    }

    public function test_a_switched_off_gateway_accepts_no_callback_and_sends_no_link(): void
    {
        $payment = $this->confirmedPurchase();
        $this->in(fn () => PaymentGateway::query()->update(['active' => false]));
        $this->paidTransaction($payment);

        $this->notify($this->payload($payment))->assertStatus(400);
        $this->assertNull($this->lead()->paid_at);
        $this->assertFalse(DigitalCheckout::applies($this->digital->fresh()));
    }

    // ---- Buyer pages --------------------------------------------------------------------------------------------

    public function test_the_access_link_opens_only_signed_in_time_and_for_a_paid_purchase(): void
    {
        $payment = $this->confirmedPurchase();
        $url = DigitalCheckout::accessUrl($payment);

        $this->get($url)->assertStatus(410);

        $this->paidTransaction($payment);
        $this->notify($this->payload($payment))->assertOk();

        $this->get($url)->assertRedirect('https://example.com/unduh/resep');
        $this->assertNotNull($payment->refresh()->access_opened_at);
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
        $this->get(route('digital.access', $payment->token))->assertForbidden();

        $this->travel(8)->days();
        $this->get($url)->assertForbidden();
    }

    public function test_the_payment_page_shows_the_status_and_the_purchase_pixel_with_the_server_event_id(): void
    {
        $payment = $this->confirmedPurchase();
        $this->in(fn () => TrackingSetting::create(['meta_pixel_id' => '1234567890']));

        $this->get('/bayar/'.$payment->token)->assertOk()->assertSee('Selesaikan pembayaran')->assertSee($payment->payment_url, false)->assertDontSee("'Purchase'", false);

        $this->paidTransaction($payment);
        $this->notify($this->payload($payment));

        $eventId = 'lead-'.$this->lead()->id.'-Purchase-'.$this->in(fn () => LeadActivity::query()->where('type', 'purchase_recorded')->max('id'));
        $this->get('/bayar/'.$payment->token)->assertOk()->assertSee('Pembayaran diterima')->assertSee("'Purchase'", false)->assertSee($eventId, false);
        $this->get('/bayar/'.str_repeat('a', 40))->assertNotFound();
    }

    public function test_one_reminder_before_the_link_expires(): void
    {
        $payment = $this->confirmedPurchase();
        $this->in(fn () => $payment->forceFill(['expires_at' => now()->addHours(2)])->save());
        $count = count($this->sent());

        Artisan::call('stages:automate');
        Artisan::call('stages:automate');

        $this->assertCount($count + 1, $this->sent());
        $this->assertStringContainsString($payment->payment_url, collect($this->sent())->last());
        $this->assertNotNull($payment->refresh()->reminded_at);
    }

    // ---- Form, buttons, settings --------------------------------------------------------------------------------

    public function test_a_digital_form_can_send_the_buyer_straight_to_the_payment_page(): void
    {
        $form = $this->in(fn () => LeadForm::create(['name' => 'Beli e-book', 'slug' => 'beli-ebook', 'status' => 'active', 'is_primary' => false,
            'design' => ['title' => 'Beli'], 'submit' => ['label' => 'Beli'], 'buttons' => [],
            'fields' => [
                ['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'required' => true, 'maps_to' => 'name'],
                ['key' => 'whatsapp', 'type' => 'phone', 'label' => 'WhatsApp', 'required' => true, 'maps_to' => 'phone'],
                ['key' => 'produk', 'type' => 'select', 'label' => 'Produk', 'required' => true, 'options' => ['E-book Resep Harian'], 'maps_to' => 'product'],
            ],
            'after_submit' => ['type' => 'payment', 'message' => 'Terima kasih!'],
        ]));

        $response = $this->postJson('/f/'.$this->digital->slug.'/'.$form->slug, ['f' => ['nama' => 'Dina', 'whatsapp' => '089900001234', 'produk' => 'E-book Resep Harian'], 'event_id' => 'ev-1']);

        $payment = $this->in(fn () => DigitalPayment::query()->firstOrFail());
        $response->assertOk()->assertJsonPath('action.type', 'redirect')->assertJsonPath('action.url', $payment->payment_url);
        $this->assertSame(49000, (int) $payment->amount);
        $this->assertSame('Menunggu pembayaran', $this->lead()->stage->name);
    }

    public function test_the_settings_page_stores_the_keys_encrypted_and_only_for_digital_agencies(): void
    {
        $this->actingInTenant($this->adminOf($this->digital), $this->digital);

        Livewire::test(PaymentGatewayPage::class)
            ->assertSee(route('digital.ipaymu.notify', $this->digital->id))
            ->set('data.va', '')->set('data.api_key', 'SANDBOX-NEW-KEY')->set('data.active', true)->set('data.invoice_hours', 12)
            ->call('save')->assertHasNoErrors();

        $gateway = $this->in(fn () => PaymentGateway::query()->firstOrFail());
        $this->assertSame(self::VA, $gateway->va, 'an empty box keeps the saved VA');
        $this->assertSame('SANDBOX-NEW-KEY', $gateway->api_key);
        $this->assertSame(12, $gateway->invoice_hours);
        $raw = DB::table('payment_gateways')->where('id', $gateway->id)->first();
        $this->assertStringNotContainsString('SANDBOX-NEW-KEY', $raw->api_key);
        $this->assertStringNotContainsString(self::VA, $raw->va);
        $this->assertArrayNotHasKey('api_key', $gateway->toArray());

        $shop = app(ProvisionTenant::class)('Toko Contoh', 'toko-contoh', User::factory()->create(), 'retail');
        $this->actingInTenant($this->adminOf($shop), $shop);
        $this->assertFalse(PaymentGatewayPage::canAccess());
    }

    public function test_an_agent_can_send_the_payment_link_from_the_inbox(): void
    {
        $this->inbound('Halo kak');
        $lead = $this->lead();
        $this->in(fn () => $lead->forceFill(['property_id' => $this->ebook->id, 'deal_value' => 49000, 'stage_id' => (new DigitalStages)->stage(DigitalStages::PAYMENT)->id])->save());
        $this->actingInTenant($this->adminOf($this->digital), $this->digital);

        Livewire::test(Inbox::class, ['leadId' => $lead->id])->callAction('digitalPaymentLink');

        $payment = $this->in(fn () => DigitalPayment::query()->firstOrFail());
        $this->assertSame(49000, (int) $payment->amount);
        $this->assertStringContainsString($payment->payment_url, collect($this->sent())->last());
    }

    public function test_the_ai_is_told_the_system_sends_the_link_and_never_writes_one(): void
    {
        $this->inbound('Halo kak');
        $tools = DigitalTools::for($this->digital, $this->lead());

        $this->assertStringContainsString('PEMBAYARAN OTOMATIS', $this->in(fn () => $tools->instructions()));
        $this->assertStringNotContainsString('REKENING:', $this->in(fn () => $tools->instructions()));
        $this->assertStringNotContainsString('example.com/unduh', $this->in(fn () => $tools->instructions()), 'the product link is never given to the AI');
        $result = $this->in(fn () => $tools->run(DigitalTools::RECORD, ['items' => [['product_id' => $this->ebook->id]], 'status' => 'konfirmasi']));
        $this->assertSame(DigitalTools::SYSTEM_SENDS_LINK_NOTE, $result['pesan']);
    }

    // ---- Regression: JITU's own subscription payments ----------------------------------------------------------

    public function test_subscription_payments_still_use_jitus_account_and_route(): void
    {
        $client = app(IpaymuClient::class);
        $this->assertTrue($client->enabled());
        $client->createPayment(['product' => ['JITU LEAD'], 'qty' => ['1'], 'price' => ['199000']]);

        $request = Http::recorded(fn (Request $r) => str_contains($r->url(), 'ipaymu.com/api/v2/payment'))->last()[0];
        $this->assertSame('0000001111111111', $request->header('va')[0]);
        $this->assertSame(url('/billing/ipaymu/notify'), route('billing.ipaymu.notify'));
        $this->assertFalse(DigitalCheckout::applies(app(ProvisionTenant::class)('Griya', 'griya', User::factory()->create(), 'properti')));
    }
}
