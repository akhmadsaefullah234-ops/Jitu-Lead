<?php

namespace Tests\Feature;

use App\Billing\Ipaymu\CallbackSignature;
use App\Billing\Ipaymu\IpaymuClient;
use App\Billing\PlanRequests;
use App\Billing\Subscriptions;
use App\Filament\Admin\Resources\IpaymuPayments\Pages\ListIpaymuPayments;
use App\Filament\Pages\Subscription as SubscriptionPage;
use App\Mail\PaymentReceived;
use App\Models\IpaymuPayment;
use App\Models\PushSubscription;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Push\PushTransport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class IpaymuTest extends TestCase
{
    use RefreshDatabase;

    private const VA = '0000001234567890';

    private const KEY = 'SANDBOX-SECRET-KEY';

    private Tenant $tenant;

    private User $owner;

    private OwnerAlertsPushTransport $push;

    private bool $txDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['services.ipaymu.va' => self::VA, 'services.ipaymu.api_key' => self::KEY, 'services.ipaymu.sandbox' => true,
            'jitu.support_email' => 'owner@example.com', 'jitu.notify_signups' => true]);
        $this->push = new OwnerAlertsPushTransport;
        $this->app->instance(PushTransport::class, $this->push);

        $this->owner = User::factory()->create(['email' => 'owner@example.com']);
        $this->owner->forceFill(['is_super_admin' => true])->save();
        $endpoint = "https://fcm.googleapis.com/fcm/send/{$this->owner->getKey()}-a";
        PushSubscription::create(['user_id' => $this->owner->getKey(), 'endpoint_hash' => PushSubscription::hash($endpoint), 'endpoint' => $endpoint, 'p256dh' => 'BKey', 'auth' => 'authkey', 'device' => 'Android · Chrome']);

        $this->tenant = $this->makeTenant();
    }

    private function admin(): User
    {
        $admin = $this->adminOf($this->tenant);
        $this->actingInTenant($admin, $this->tenant);

        return $admin;
    }

    /** A pending request for the Tim plan, monthly (Rp 199.000). */
    private function request(): SubscriptionRequest
    {
        return PlanRequests::create($this->tenant, $this->adminOf($this->tenant), 'tim', 'monthly');
    }

    private function fakeIpaymu(array $transaction = []): void
    {
        Http::fake([
            'sandbox.ipaymu.com/api/v2/payment' => Http::response(['Status' => 200, 'Success' => true, 'Message' => 'success', 'Data' => ['SessionID' => 'SESS-1', 'Url' => 'https://sandbox.ipaymu.com/payment/SESS-1']]),
            'sandbox.ipaymu.com/api/v2/transaction' => fn () => $this->txDown ? Http::response('boom', 500) : Http::response(['Status' => 200, 'Success' => true, 'Message' => 'success', 'Data' => $transaction + [
                'TransactionId' => 777, 'SessionId' => 'SESS-1', 'ReferenceId' => $this->ref(), 'Amount' => 199000, 'Status' => 1, 'StatusDesc' => 'Berhasil',
            ]]),
        ]);
    }

    /** Pays through the page so a payment row with reference "1" exists. */
    private function startPayment(): IpaymuPayment
    {
        $this->request();
        $this->admin();
        Livewire::test(SubscriptionPage::class)->call('pay');

        return IpaymuPayment::firstOrFail();
    }

    /** The reference of the newest payment (the request id, which the test database does not start at 1). */
    private function ref(): string
    {
        return (string) IpaymuPayment::query()->latest('id')->value('reference_id');
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'trx_id' => '777', 'status' => 'berhasil', 'status_code' => '1', 'sid' => 'SESS-1', 'reference_id' => $this->ref(), 'referenceId' => $this->ref(),
            'amount' => '199000', 'via' => 'qris', 'channel' => 'qris', 'paid_off' => '1', 'is_escrow' => 'false', 'additional_info' => '[]',
            'url' => 'https://example.com/billing/ipaymu/notify', 'buyer_name' => 'Admin Griya', 'transaction_status_code' => '1',
        ];
    }

    /** Signs like the iPaymu docs: normalized types, keys sorted A-Z, JSON with escaped slashes, HMAC-SHA256 keyed with the VA. */
    private function sign(array $payload, string $va = self::VA): string
    {
        $data = $payload;
        foreach (['trx_id', 'status_code', 'transaction_status_code', 'paid_off'] as $k) {
            isset($data[$k]) && $data[$k] = (int) $data[$k];
        }
        $data['is_escrow'] = filter_var($data['is_escrow'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $data['additional_info'] = [];
        ksort($data, SORT_STRING);

        return hash_hmac('sha256', str_replace('/', '\/', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $va);
    }

    private function notify(array $payload, ?string $signature = null)
    {
        return $this->call('POST', '/billing/ipaymu/notify', [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_X_SIGNATURE' => $signature ?? $this->sign($payload),
        ], http_build_query($payload));
    }

    // --- request signature and transaction ---

    public function test_the_request_signature_follows_the_docs(): void
    {
        $json = '{"product":["A"],"qty":["1"],"price":["1000"]}';
        $expected = hash_hmac('sha256', 'POST:'.self::VA.':'.hash('sha256', $json).':'.self::KEY, self::KEY);

        $this->assertSame($expected, app(IpaymuClient::class)->signature($json, self::VA, self::KEY));
        $this->assertSame(64, strlen($expected));
    }

    public function test_paying_creates_a_signed_transaction_and_sends_the_buyer_to_ipaymu(): void
    {
        $this->fakeIpaymu();
        $request = $this->request();
        $admin = $this->admin();

        Livewire::test(SubscriptionPage::class)->call('pay')->assertRedirect('https://sandbox.ipaymu.com/payment/SESS-1');

        Http::assertSent(function (Request $r) use ($request, $admin) {
            if (! str_ends_with($r->url(), '/api/v2/payment')) {
                return false;
            }

            $body = $r->data();
            $this->assertSame([self::VA, true], [$r->header('va')[0], preg_match('/^\d{14}$/', $r->header('timestamp')[0]) === 1]);
            $this->assertSame(app(IpaymuClient::class)->signature($r->body(), self::VA, self::KEY), $r->header('signature')[0]);
            $this->assertStringNotContainsString(self::KEY, $r->body());
            $this->assertSame((string) $request->getKey(), $body['referenceId']);
            $this->assertSame(['199000'], $body['price']);
            $this->assertSame(['1'], $body['qty']);
            $this->assertStringContainsString('Tim', $body['product'][0]);
            $this->assertStringContainsString('Bulanan', $body['product'][0]);
            $this->assertSame(url('/billing/ipaymu/notify'), $body['notifyUrl']);
            $this->assertSame(SubscriptionPage::getUrl(), $body['returnUrl']);
            $this->assertSame(SubscriptionPage::getUrl(), $body['cancelUrl']);
            $this->assertSame([$admin->name, $admin->email], [$body['buyerName'], $body['buyerEmail']]);

            return true;
        });

        $payment = IpaymuPayment::firstOrFail();
        $this->assertSame(['SESS-1', IpaymuPayment::PENDING, 199000, (string) $request->getKey()], [$payment->session_id, $payment->status, (int) $payment->amount, $payment->reference_id]);
    }

    public function test_the_pay_button_is_hidden_without_keys_and_the_manual_flow_stays(): void
    {
        config(['services.ipaymu.va' => null, 'services.ipaymu.api_key' => null, 'jitu.billing_bank_info' => 'Bank ABC 123456']);
        $this->admin();

        Livewire::test(SubscriptionPage::class)->call('choose', 'tim')
            ->assertDontSee('Bayar sekarang')->assertDontSee('Bayar otomatis')->assertSee('Bank ABC 123456')
            ->call('pay');

        Http::assertNothingSent();
        $this->assertSame(0, IpaymuPayment::count());
    }

    public function test_the_pay_button_shows_when_configured(): void
    {
        $this->admin();

        Livewire::test(SubscriptionPage::class)->call('choose', 'tim')->assertSee('Bayar sekarang (QRIS / Virtual Account / e-wallet)')->assertSee('transfer manual');
    }

    public function test_a_failing_ipaymu_call_leaves_the_manual_flow_working(): void
    {
        Http::fake(['sandbox.ipaymu.com/*' => Http::response(['Status' => 401, 'Message' => 'bad signature'], 401)]);
        $this->request();
        $this->admin();

        Livewire::test(SubscriptionPage::class)->call('pay')->assertNoRedirect();

        $this->assertSame(0, IpaymuPayment::count());
        $this->assertSame(SubscriptionRequest::PENDING, SubscriptionRequest::first()->status);
    }

    public function test_an_open_payment_page_is_reused_and_an_expired_one_is_replaced(): void
    {
        $this->fakeIpaymu();
        $this->startPayment();

        Livewire::test(SubscriptionPage::class)->call('pay')->assertRedirect('https://sandbox.ipaymu.com/payment/SESS-1');
        $this->assertSame(1, IpaymuPayment::count());

        IpaymuPayment::query()->update(['expires_at' => now()->subHour()]);
        Livewire::test(SubscriptionPage::class)->assertSee('Kedaluwarsa')->assertSee('Bayar ulang')->call('pay');

        $first = (string) SubscriptionRequest::firstOrFail()->getKey();
        $this->assertSame([$first, $first.'-2'], IpaymuPayment::orderBy('id')->pluck('reference_id')->all());
    }

    // --- callback ---

    public function test_a_successful_callback_activates_the_plan_and_tells_the_owner(): void
    {
        $this->fakeIpaymu();
        $payment = $this->startPayment();

        $this->notify($this->payload())->assertOk();

        $sub = $this->tenant->currentSubscription()->refresh();
        $this->assertSame(['tim', Subscription::ACTIVE], [$sub->plan, $sub->status]);
        $this->assertTrue($sub->current_period_end->isFuture());
        $this->assertSame(SubscriptionRequest::APPROVED, SubscriptionRequest::first()->status);

        $payment->refresh();
        $this->assertSame([IpaymuPayment::PAID, '777', 'qris'], [$payment->status, $payment->trx_id, $payment->channel]);
        $this->assertNotNull($payment->paid_at);

        Mail::assertQueued(PaymentReceived::class, function (PaymentReceived $m) {
            $this->assertTrue($m->hasTo('owner@example.com'));
            $this->assertSame('Pembayaran diterima: Griya Prima Tim Kecil Rp 199.000 via iPaymu', $m->envelope()->subject);

            return true;
        });
        $this->assertSame('Pembayaran diterima: Griya Prima Tim Kecil Rp 199.000 via iPaymu', end($this->push->sent)['payload']['title']);
    }

    public function test_the_callback_asks_ipaymu_again_and_a_pending_answer_does_not_activate(): void
    {
        $this->fakeIpaymu(['Status' => 0, 'StatusDesc' => 'Pending']);
        $this->startPayment();

        $this->notify($this->payload())->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v2/transaction') && $r->data() === ['transactionId' => '777']);
        $this->assertNotSame('tim', $this->tenant->currentSubscription()->refresh()->plan);
        $this->assertSame(IpaymuPayment::PENDING, IpaymuPayment::first()->status);
        Mail::assertNotQueued(PaymentReceived::class);
    }

    public function test_a_repeated_callback_activates_only_once(): void
    {
        $this->fakeIpaymu();
        $this->startPayment();

        $this->notify($this->payload())->assertOk();
        $end = $this->tenant->currentSubscription()->refresh()->current_period_end;

        $this->notify($this->payload())->assertOk();
        $this->notify($this->payload())->assertOk();

        $this->assertTrue($end->equalTo($this->tenant->currentSubscription()->refresh()->current_period_end));
        Mail::assertQueued(PaymentReceived::class, 1);
        $this->assertCount(1, array_filter($this->push->sent, fn ($p) => str_starts_with($p['payload']['title'], 'Pembayaran diterima')));
    }

    public function test_a_wrong_amount_is_rejected(): void
    {
        $this->fakeIpaymu(['Amount' => 1000]);
        $this->startPayment();

        $this->notify($this->payload())->assertStatus(422);

        $this->assertSame(IpaymuPayment::PENDING, IpaymuPayment::first()->status);
        $this->assertNotSame('tim', $this->tenant->currentSubscription()->refresh()->plan);
    }

    public function test_a_mismatching_reference_from_ipaymu_is_rejected(): void
    {
        $this->fakeIpaymu(['ReferenceId' => '999']);
        $this->startPayment();

        $this->notify($this->payload())->assertStatus(422);
        $this->assertSame(IpaymuPayment::PENDING, IpaymuPayment::first()->status);
    }

    public function test_an_unknown_reference_is_rejected(): void
    {
        $this->fakeIpaymu();
        $this->startPayment();
        $payload = $this->payload(['reference_id' => '4040', 'referenceId' => '4040']);

        $this->notify($payload)->assertStatus(422);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/v2/transaction'));
    }

    public function test_a_forged_or_unsigned_callback_is_rejected_without_calling_ipaymu(): void
    {
        $this->fakeIpaymu();
        $this->startPayment();

        $this->notify($this->payload(), 'deadbeef')->assertStatus(400);
        $this->notify($this->payload(), $this->sign($this->payload(), 'another-va'))->assertStatus(400);
        $this->call('POST', '/billing/ipaymu/notify', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($this->payload()))->assertStatus(400);

        // the signature covers the fields: a tampered amount no longer matches
        $signed = $this->sign($this->payload());
        $this->notify($this->payload(['amount' => '1']), $signed)->assertStatus(400);

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/v2/transaction'));
        $this->assertNotSame('tim', $this->tenant->currentSubscription()->refresh()->plan);
        Mail::assertNotQueued(PaymentReceived::class);
    }

    public function test_a_json_callback_with_the_same_fields_verifies(): void
    {
        $this->fakeIpaymu();
        $this->startPayment();
        $payload = ['trx_id' => 777, 'status' => 'berhasil', 'status_code' => 1, 'reference_id' => $this->ref(), 'amount' => '199000', 'is_escrow' => false, 'paid_off' => 1,
            'additional_info' => [], 'url' => 'https://example.com/x', 'via' => 'va', 'settlement_date' => null, 'transaction_status_code' => 1];

        $this->assertTrue(CallbackSignature::valid($payload, $this->sign($payload), self::VA));

        $this->call('POST', '/billing/ipaymu/notify', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => $this->sign($payload)], json_encode($payload))->assertOk();
        $this->assertSame('tim', $this->tenant->currentSubscription()->refresh()->plan);
    }

    public function test_an_expired_or_failed_status_is_recorded_and_never_activates(): void
    {
        $this->fakeIpaymu(['Status' => -2, 'StatusDesc' => 'Expired']);
        $this->startPayment();
        $this->notify($this->payload(['status' => 'expired', 'status_code' => '-2']))->assertOk();
        $this->assertSame(IpaymuPayment::EXPIRED, IpaymuPayment::first()->status);

        $this->assertNotSame('tim', $this->tenant->currentSubscription()->refresh()->plan);
        Mail::assertNotQueued(PaymentReceived::class);
    }

    public function test_a_failed_status_is_recorded_and_never_activates(): void
    {
        $this->fakeIpaymu(['Status' => 5, 'StatusDesc' => 'Failed']);
        $this->startPayment();

        $this->notify($this->payload(['status' => 'gagal', 'status_code' => '5']))->assertOk();

        $this->assertSame(IpaymuPayment::FAILED, IpaymuPayment::first()->status);
        $this->assertNotSame('tim', $this->tenant->currentSubscription()->refresh()->plan);
        Mail::assertNotQueued(PaymentReceived::class);
    }

    public function test_ipaymu_being_unreachable_asks_for_a_retry_and_activates_nothing(): void
    {
        $this->fakeIpaymu();
        $this->startPayment();
        $this->txDown = true;

        $this->notify($this->payload())->assertStatus(503);
        $this->assertSame(IpaymuPayment::PENDING, IpaymuPayment::first()->status);
    }

    public function test_the_callback_is_ignored_when_ipaymu_is_not_configured(): void
    {
        config(['services.ipaymu.va' => null, 'services.ipaymu.api_key' => null]);

        $this->notify($this->payload(), 'x')->assertStatus(400);
    }

    public function test_a_payment_after_a_manual_approval_does_not_extend_the_plan_again(): void
    {
        $this->fakeIpaymu();
        $request = $this->request();
        $this->admin();
        Livewire::test(SubscriptionPage::class)->call('pay');

        app(Subscriptions::class)->activate($this->tenant, 'tim', 'monthly', 1);
        $end = $this->tenant->currentSubscription()->refresh()->current_period_end;

        $this->notify($this->payload())->assertOk();

        $this->assertTrue($end->equalTo($this->tenant->currentSubscription()->refresh()->current_period_end));
        $this->assertSame(IpaymuPayment::PAID, IpaymuPayment::first()->status);
        Mail::assertQueued(PaymentReceived::class, fn (PaymentReceived $m) => str_contains($m->details['Catatan'], 'pembayaran ganda'));
    }

    // --- screens ---

    public function test_the_subscription_page_shows_the_payment_status(): void
    {
        $this->fakeIpaymu();
        $this->startPayment();

        Livewire::test(SubscriptionPage::class)->assertSee('Status pembayaran iPaymu')->assertSee('Menunggu pembayaran')->assertSee('Lanjutkan pembayaran');

        $this->notify($this->payload())->assertOk();
        $this->admin();

        Livewire::test(SubscriptionPage::class)->assertSee('Berhasil')->assertSee('Paket sudah aktif');
    }

    public function test_the_owner_sees_the_transactions_in_admin(): void
    {
        $this->fakeIpaymu();
        $this->startPayment();
        $this->notify($this->payload())->assertOk();

        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListIpaymuPayments::class)->assertOk()
            ->assertSee('Griya Prima')->assertSee('Berhasil')->assertSee('777')->assertSee('199.000');

        $this->actingAs(User::factory()->create())->get('/admin/ipaymu-payments')->assertForbidden();
    }
}
