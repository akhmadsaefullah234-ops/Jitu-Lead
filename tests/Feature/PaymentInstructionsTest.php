<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Actions\RecordOrder;
use App\Ai\ConversationHistory;
use App\Ai\OrderConfirmation;
use App\Ai\ShopTools;
use App\Enums\AiMode;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\PaymentSettingsPage;
use App\Models\Lead;
use App\Models\PaymentAccount;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Payments\PaymentInstructions;
use App\Payments\PaymentSettings;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tahap 1 pembayaran: rekening per tenant (Pengaturan > Pembayaran) dan instruksi bayar transfer yang disusun dan
 * dikirim sistem. Tenant tanpa rekening aktif berperilaku persis seperti sebelumnya.
 */
class PaymentInstructionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private Property $kaos;

    /** @var list<array> Messages API responses, served in order */
    private array $ai = [];

    /** @var list<array> What the AI was sent, per call */
    private array $aiRequests = [];

    private int $n = 0;

    private bool $gatewayDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->kaos = $this->inShop(fn () => Property::create(['name' => 'Kaos Lari', 'price_from' => 89000, 'weight_grams' => 300]));

        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => function (Request $r) {
                $this->aiRequests[] = $r->data();

                return Http::response(array_shift($this->ai) ?? $this->text('Baik, Kak.'));
            },
            'gw.example.com/*' => fn () => $this->gatewayDown ? Http::response(['error' => 'down'], 500) : Http::response(['id' => 'g'.++$this->n]),
        ]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function inShop(callable $fn, ?Tenant $tenant = null): mixed
    {
        return app(CurrentTenant::class)->run($tenant ?? $this->shop, $fn);
    }

    private function text(string $text): array
    {
        return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function toolUse(string $name, array $input): array
    {
        return ['stop_reason' => 'tool_use', 'content' => [
            ['type' => 'text', 'text' => 'Saya catat dulu ya, Kak.'],
            ['type' => 'tool_use', 'id' => 'tu_'.++$this->n, 'name' => $name, 'input' => $input],
        ]];
    }

    private function channel(AiMode $mode = AiMode::Off, ?Tenant $tenant = null): WaChannel
    {
        return $this->inShop(fn () => WaChannel::query()->first() ?? WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+62811000000'.str_pad((string) ($tenant ?? $this->shop)->id, 2, '0', STR_PAD_LEFT),
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => $mode,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]), $tenant);
    }

    private function inbound(string $text, string $phone = '6281234567890', ?Tenant $tenant = null): WaMessage
    {
        $channel = $this->channel(tenant: $tenant);

        return $this->inShop(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize($phone), 'text', $text, null, CarbonImmutable::now(), 'Sari',
        )), $tenant);
    }

    private function order(array $extra = []): array
    {
        return $extra + [
            'items' => [['product_id' => $this->kaos->id, 'varian' => 'Hitam L', 'jumlah' => 2]],
            'nama' => 'Sari Wulandari', 'alamat' => 'Jl. Melati No. 3, RT 02/RW 05, Tembalang', 'kota' => 'Semarang', 'provinsi' => 'Jawa Tengah',
            'status' => 'konfirmasi', 'kurir' => 'JNE REG', 'pembayaran' => 'transfer',
        ];
    }

    private function account(string $bank, string $number, int $sort = 0, bool $active = true, ?Tenant $tenant = null): PaymentAccount
    {
        return $this->inShop(fn () => PaymentAccount::create(['bank_name' => $bank, 'account_number' => $number, 'account_holder' => 'Quicker Sport', 'active' => $active, 'sort_order' => $sort]), $tenant);
    }

    private function settings(array $settings, ?Tenant $tenant = null): void
    {
        ($tenant ?? $this->shop)->forceFill(['payment_settings' => $settings])->save();
        ($tenant ?? $this->shop)->refresh();
    }

    /** A lead with an open chat (the buyer wrote first), ready for RecordOrder. */
    private function chattingLead(string $phone = '6281234567890', ?Tenant $tenant = null): Lead
    {
        $message = $this->inbound('Halo kak, mau order', $phone, $tenant);

        return $this->inShop(fn () => $message->conversation->lead()->firstOrFail(), $tenant);
    }

    private function record(Lead $lead, array $extra = [], ?Tenant $tenant = null): array
    {
        $tenant ??= $this->shop;

        return $this->inShop(fn () => app(RecordOrder::class)($lead->fresh(), $this->order($extra), $this->adminOf($tenant), 'AI'), $tenant);
    }

    /** @return Collection<int, WaMessage> */
    private function outbound(Lead $lead, ?Tenant $tenant = null)
    {
        return $this->inShop(fn () => WaMessage::query()->whereHas('conversation', fn ($q) => $q->where('lead_id', $lead->id))->where('direction', 'out')->orderBy('id')->get(), $tenant);
    }

    // ---- Tenant tanpa rekening: perilaku lama ------------------------------------------------------------------

    public function test_a_tenant_without_accounts_keeps_the_old_behaviour(): void
    {
        $this->channel(AiMode::Auto);
        $this->ai = [$this->toolUse(ShopTools::RECORD, $this->order()), $this->text('Pesanan dicatat, silakan transfer ke rekening BCA ya Kak.')];

        $this->inbound('Oke kak, transfer');

        $lead = $this->inShop(fn () => Lead::query()->firstOrFail());
        $this->assertSame('Menunggu pembayaran', $this->inShop(fn () => $lead->fresh('stage')->stage->name));
        $this->assertSame(['ai'], $this->outbound($lead)->pluck('origin')->all());
        $this->assertNull($lead->fresh()->payment_amount);
        $this->assertNull($lead->fresh()->payment_unique_code);
        $this->assertFalse($this->inShop(fn () => $lead->activities()->where('type', 'payment_instruction')->exists()));

        // The model was told what it was told before, and never about the system sending the account.
        $sent = json_encode($this->aiRequests, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Minta pembeli transfer sebesar total', $sent);
        $this->assertStringNotContainsString('DIKIRIM OTOMATIS', $sent);
        $this->assertStringNotContainsString('REKENING: nomor rekening', $sent);

        // Old notes and fallback texts are unchanged.
        $this->assertStringContainsString('rekening sesuai PENGETAHUAN', OrderConfirmation::recordedNote(['total' => 1], 'transfer'));
        $this->assertStringContainsString('Silakan transfer sesuai total', OrderConfirmation::fallback(['total_teks' => 'Rp 1.000'], 'transfer', [], $this->shop));
    }

    public function test_inactive_accounts_only_count_as_no_accounts(): void
    {
        $this->account('BCA', '1234567890', active: false);
        $lead = $this->chattingLead();

        $result = $this->record($lead);

        $this->assertSame('tercatat', $result['status']);
        $this->assertArrayNotHasKey('instruksi_bayar', $result);
        $this->assertSame(['payment' => 0], ['payment' => $this->outbound($lead)->where('origin', 'payment')->count()]);
        $this->assertFalse(PaymentInstructions::applies($this->shop));
    }

    // ---- Satu dan dua rekening ----------------------------------------------------------------------------------

    public function test_the_ai_order_gets_the_system_instruction_after_its_reply(): void
    {
        $this->account('BCA', '1234567890');
        $this->channel(AiMode::Auto);
        $this->ai = [$this->toolUse(ShopTools::RECORD, $this->order()), $this->text('Siap Kak, pesanan dicatat. Instruksi pembayarannya dikirim di pesan berikut ya.')];
        Log::spy();

        $this->inbound('Oke kak, transfer');

        $lead = $this->inShop(fn () => Lead::query()->firstOrFail())->fresh();
        $messages = $this->outbound($lead);
        $this->assertSame(['ai', 'payment'], $messages->pluck('origin')->all(), 'AI reply first, then the instruction');
        $instruction = (string) $messages->last()->body;
        $this->assertStringContainsString('BCA 1234567890 a.n. Quicker Sport', $instruction);
        $this->assertStringContainsString('Total transfer: *Rp '.number_format((int) $lead->deal_value, 0, ',', '.').'*', $instruction);
        $this->assertStringContainsString('kak Sari,', $instruction);
        $this->assertStringNotContainsString('kode unik', $instruction, 'kode unik is off: its line is left out');
        $this->assertStringNotContainsString('Mohon transfer sebelum', $instruction, 'no deadline: its line is left out');
        $this->assertSame((int) $lead->deal_value, $lead->payment_amount);
        $this->assertNotNull($lead->payment_instruction_sent_at);

        // The model is told the system sends it, and never sees the number.
        $sent = json_encode($this->aiRequests, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('DIKIRIM OTOMATIS', $sent);
        $this->assertStringContainsString('REKENING: nomor rekening', $sent);
        $this->assertStringNotContainsString('1234567890', $sent);

        // The lead's activity says what was sent, without the account number; nothing is logged with it.
        $activity = $this->inShop(fn () => $lead->activities()->where('type', 'payment_instruction')->firstOrFail());
        $this->assertStringContainsString('Instruksi bayar transfer dikirim sistem', $activity->body);
        $this->assertStringNotContainsString('1234567890', $activity->body.json_encode($activity->meta));
        Log::shouldNotHaveReceived('info', [\Mockery::on(fn ($m) => str_contains((string) $m, '1234567890')), \Mockery::any()]);

        // Still: the AI never says the payment arrived, and the lead waits for the admin's check.
        $this->assertNull($lead->paid_at);
        $this->assertFalse(ShopTools::claimsPaymentReceived($instruction));
    }

    public function test_one_top_account_by_default_and_all_accounts_when_set(): void
    {
        $this->account('Mandiri', '1400012345678', sort: 1);
        $this->account('BCA', '1234567890', sort: 0);
        $this->account('BRI', '0021019876543', sort: 2, active: false);
        $lead = $this->chattingLead();

        $result = $this->record($lead);
        $this->assertSame('terkirim', $result['instruksi_bayar']);
        $body = (string) $this->outbound($lead)->where('origin', 'payment')->last()->body;
        $this->assertStringContainsString('BCA 1234567890', $body);
        $this->assertStringNotContainsString('Mandiri', $body);

        $this->settings(['semua_rekening' => true]);
        $this->record($lead);
        $body = (string) $this->outbound($lead)->where('origin', 'payment')->last()->body;
        $this->assertStringContainsString("- BCA 1234567890 a.n. Quicker Sport\n- Mandiri 1400012345678 a.n. Quicker Sport", $body);
        $this->assertStringNotContainsString('BRI', $body, 'an inactive account is never sent');
    }

    public function test_cash_on_delivery_gets_no_instruction(): void
    {
        $this->account('BCA', '1234567890');
        $lead = $this->chattingLead();

        $this->record($lead, ['pembayaran' => 'cod']);

        $this->assertSame(0, $this->outbound($lead)->where('origin', 'payment')->count());
        $this->assertNull($lead->fresh()->payment_amount);
    }

    public function test_an_instruction_that_cannot_be_sent_is_noted_for_an_agent(): void
    {
        $this->account('BCA', '1234567890');
        $lead = $this->chattingLead();
        $this->gatewayDown = true;

        $result = $this->record($lead);

        $this->assertSame('tidak_terkirim', $result['instruksi_bayar']);
        $this->assertSame(0, $this->outbound($lead)->where('origin', 'payment')->where('status', '!=', MessageStatus::Failed)->count());
        $note = $this->inShop(fn () => $lead->activities()->where('type', 'payment_instruction')->value('body'));
        $this->assertStringContainsString('tidak terkirim otomatis', $note);
        $this->assertStringNotContainsString('1234567890', $note);
        $this->assertSame((int) $lead->fresh()->deal_value, $lead->fresh()->payment_amount, 'the amount is kept for the agent');
    }

    // ---- Kode unik dan batas bayar -------------------------------------------------------------------------------

    public function test_the_unique_code_is_added_to_the_total_and_kept_on_the_lead(): void
    {
        $this->account('BCA', '1234567890');
        $this->settings(['kode_unik' => true, 'batas_waktu_bayar' => 24]);
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Asia/Jakarta'));
        $lead = $this->chattingLead();

        $result = $this->record($lead);
        $lead = $lead->fresh();

        $code = $lead->payment_unique_code;
        $this->assertGreaterThanOrEqual(100, $code);
        $this->assertLessThanOrEqual(999, $code);
        $this->assertSame((int) $result['total'], (int) $lead->deal_value, 'deal_value (and the Purchase event) stays the order total');
        $this->assertSame((int) $lead->deal_value + $code, $lead->payment_amount);
        $this->assertSame($lead->payment_amount, $result['nominal_transfer']);
        $this->assertSame('2026-11-24 10:00', $lead->payment_due_at->timezone('Asia/Jakarta')->format('Y-m-d H:i'));

        $body = (string) $this->outbound($lead)->where('origin', 'payment')->last()->body;
        $this->assertStringContainsString('*Rp '.number_format($lead->payment_amount, 0, ',', '.').'*', $body);
        $this->assertStringContainsString('3 digit terakhir ('.$code.') adalah kode unik', $body);
        $this->assertStringContainsString('Mohon transfer sebelum Selasa, 24 November 2026 pukul 10.00 WIB.', $body);

        // The admin's "Pembayaran diterima" button shows the amount to look for.
        $this->assertStringStartsWith('Nominal yang ditunggu: Rp '.number_format($lead->payment_amount, 0, ',', '.').' (kode unik '.$code.').', PaymentInstructions::confirmDescription($lead));
        $this->assertSame('Pastikan dana sudah ada di rekening. Lead pindah ke tahap Dikemas & dikirim.', PaymentInstructions::confirmDescription(new Lead));

        // Confirmed again: the same code.
        $this->record($lead);
        $this->assertSame($code, $lead->fresh()->payment_unique_code);

        // Another buyer with the same total waiting to pay: never the same amount.
        $other = $this->chattingLead('6281298765432');
        $this->record($other);
        $this->assertNotSame($lead->fresh()->payment_amount, $other->fresh()->payment_amount);
    }

    // ---- Template ------------------------------------------------------------------------------------------------

    public function test_the_template_fills_every_variable_and_drops_empty_lines(): void
    {
        $accounts = collect([new PaymentAccount(['bank_name' => 'BCA', 'account_number' => '1234567890', 'account_holder' => 'Toko A'])]);
        $template = "Halo {nama}!\nBayar {total} ke {rekening}\nKode: {kode_unik}\nBatas: {batas_bayar}\nTerima kasih";

        $full = PaymentInstructions::render($template, ['total' => 231123, 'rekening' => $accounts, 'kode_unik' => 123,
            'batas_bayar' => CarbonImmutable::parse('2026-11-24 03:00', 'UTC'), 'nama' => 'Budi Santoso'], $this->shop);
        $this->assertSame("Halo Budi!\nBayar Rp 231.123 ke BCA 1234567890 a.n. Toko A\nKode: 123\nBatas: Selasa, 24 November 2026 pukul 10.00 WIB\nTerima kasih", $full);

        $bare = PaymentInstructions::render($template, ['total' => 231000, 'rekening' => $accounts, 'kode_unik' => null, 'batas_bayar' => null, 'nama' => null], $this->shop);
        $this->assertSame("Halo!\nBayar Rp 231.000 ke BCA 1234567890 a.n. Toko A\nTerima kasih", $bare);
    }

    public function test_the_ai_reads_the_instruction_without_the_account_number(): void
    {
        $this->account('BCA', '1234567890');
        $lead = $this->chattingLead();
        $this->record($lead);
        $this->inbound('Siap kak');

        $conversation = $this->inShop(fn () => $lead->conversation()->firstOrFail());
        $history = json_encode($this->inShop(fn () => ConversationHistory::load($conversation)->turns), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('[Instruksi bayar otomatis dari sistem]', $history);
        $this->assertStringContainsString('[nomor rekening]', $history);
        $this->assertStringContainsString('Rp '.number_format((int) $lead->fresh()->deal_value, 0, ',', '.'), $history, 'the amount stays readable');
        $this->assertStringNotContainsString('1234567890', $history);
    }

    public function test_an_ok_to_the_instruction_does_not_record_the_order_again(): void
    {
        $this->account('BCA', '1234567890');
        $lead = $this->chattingLead();
        $this->record($lead);
        $ok = $this->inbound('ok kak');

        $this->assertNull($this->inShop(fn () => OrderConfirmation::pending($this->shop, $lead->fresh(), $ok)));
    }

    // ---- Isolasi antar tenant ------------------------------------------------------------------------------------

    public function test_accounts_never_cross_tenants(): void
    {
        $other = app(ProvisionTenant::class)('Toko Lain', 'toko-lain', User::factory()->create(), 'retail');
        $other->forceFill(['shipping' => $this->shop->fresh()->shipping])->save();
        $theirs = $this->account('BNI', '9998887776', tenant: $other);
        $this->account('BCA', '1234567890');

        // Without a current tenant (queue, console) the settings still read only their own tenant's accounts.
        app(CurrentTenant::class)->set(null);
        $this->assertSame(['BCA'], PaymentSettings::of($this->shop)->accounts()->pluck('bank_name')->all());
        $this->assertSame(['BNI'], PaymentSettings::of($other)->accounts()->pluck('bank_name')->all());
        $this->assertSame(1, $this->inShop(fn () => PaymentAccount::query()->count()));

        // An admin of this shop cannot edit the other tenant's account through the page, even with its id.
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        Livewire::test(PaymentSettingsPage::class)
            ->set('data.accounts', [['id' => $theirs->id, 'bank_name' => 'Curang', 'account_number' => '111111', 'account_holder' => 'X', 'active' => true]])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('BNI', PaymentAccount::withoutGlobalScopes()->find($theirs->id)->bank_name);
        $this->assertSame(['Curang'], PaymentAccount::withoutGlobalScopes()->where('tenant_id', $this->shop->id)->pluck('bank_name')->all());

        // The other tenant's order uses its own account.
        $lead = $this->chattingLead('6281211112222', $other);
        $this->inShop(fn () => Property::create(['name' => 'Kaos Lari', 'price_from' => 89000, 'weight_grams' => 300]), $other);
        $product = $this->inShop(fn () => Property::query()->firstOrFail(), $other);
        $result = $this->inShop(fn () => app(RecordOrder::class)($lead->fresh(), ['items' => [['product_id' => $product->id, 'varian' => 'L', 'jumlah' => 1]]] + $this->order(), $this->adminOf($other), 'AI'), $other);
        $this->assertSame('terkirim', $result['instruksi_bayar'] ?? null, json_encode($result));
        $body = (string) $this->outbound($lead, $other)->where('origin', 'payment')->last()?->body;
        $this->assertStringContainsString('BNI 9998887776', $body);
        $this->assertStringNotContainsString('Curang', $body);
    }

    // ---- Halaman Pengaturan > Pembayaran -------------------------------------------------------------------------

    public function test_the_admin_manages_accounts_and_sees_a_preview(): void
    {
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(PaymentSettingsPage::class)
            ->assertSee('Pratinjau pesan')
            ->set('data.accounts', [
                ['id' => null, 'bank_name' => 'Mandiri', 'account_number' => '1400012345678', 'account_holder' => 'Quicker Sport', 'active' => true],
                ['id' => null, 'bank_name' => 'BCA', 'account_number' => '1234567890', 'account_holder' => 'Quicker Sport', 'active' => false],
            ])
            ->set('data.kode_unik', true)
            ->set('data.batas_waktu_bayar', 12)
            ->assertSee('Mandiri 1400012345678 a.n. Quicker Sport')
            ->assertSee('Rp 231.123')
            ->call('save')->assertHasNoFormErrors();

        $accounts = $this->inShop(fn () => PaymentAccount::query()->orderBy('sort_order')->get());
        $this->assertSame([['Mandiri', true, 0], ['BCA', false, 1]], $accounts->map(fn ($a) => [$a->bank_name, $a->active, $a->sort_order])->all());
        $this->assertSame(['batas_waktu_bayar' => 12, 'kode_unik' => true, 'semua_rekening' => false, 'template_instruksi_bayar' => PaymentSettings::DEFAULT_TEMPLATE], $this->shop->fresh()->payment_settings);

        // Reordered and one removed.
        Livewire::test(PaymentSettingsPage::class)
            ->set('data.accounts', [['id' => $accounts[1]->id, 'bank_name' => 'BCA', 'account_number' => '1234567890', 'account_holder' => 'Quicker Sport', 'active' => true]])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame([[$accounts[1]->id, 'BCA', true, 0]], $this->inShop(fn () => PaymentAccount::query()->get()->map(fn ($a) => [$a->id, $a->bank_name, $a->active, $a->sort_order])->all()));
    }

    public function test_the_template_must_hold_the_total_and_the_account(): void
    {
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(PaymentSettingsPage::class)
            ->set('data.template_instruksi_bayar', 'Silakan bayar {total} ya kak')
            ->call('save')->assertHasErrors(['data.template_instruksi_bayar']);

        $this->assertNull($this->shop->fresh()->payment_settings);
    }

    public function test_the_page_is_for_admins_of_every_industry(): void
    {
        $property = $this->makeTenant('griya-prima');
        $this->actingInTenant($this->adminOf($property), $property);
        $this->assertTrue(PaymentSettingsPage::canAccess());

        $this->actingInTenant($this->member($this->shop, Role::Agent), $this->shop);
        $this->assertFalse(PaymentSettingsPage::canAccess());
    }
}
