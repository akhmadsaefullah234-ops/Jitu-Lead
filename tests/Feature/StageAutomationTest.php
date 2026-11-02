<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\MoveLeadToStage;
use App\Actions\ProvisionTenant;
use App\Actions\RunFollowUps;
use App\Ai\ShopTools;
use App\Automation\RetailStages;
use App\Automation\StageAutomation;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\StageAutomationPage;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Mail\PaymentProofReceived;
use App\Models\AiDraft;
use App\Models\FollowUpLog;
use App\Models\FollowUpRule;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** A shop's leads move through the retail pipeline by themselves (Pengaturan > Otomatisasi tahap), one test group per rule. */
class StageAutomationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private Property $kaos;

    /** @var list<array> Messages API responses, served in order */
    private array $ai = [];

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->kaos = $this->inShop(fn () => Property::create(['name' => 'Kaos Lari', 'price_from' => 89000, 'weight_grams' => 300]));

        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => fn () => Http::response(array_shift($this->ai) ?? $this->text('Baik, Kak.')),
            'gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n]),
        ]);
        Mail::fake();
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

    private function channel(AiMode $mode = AiMode::Off): WaChannel
    {
        return $this->inShop(fn () => WaChannel::query()->first() ?? WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => $mode,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function inbound(string $text, string $type = 'text', string $phone = '6281234567890'): WaMessage
    {
        $channel = $this->channel();

        return $this->inShop(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize($phone), $type, $text === '' ? null : $text, null, CarbonImmutable::now(), 'Sari',
        )));
    }

    private function shopStage(string $key): Stage
    {
        return $this->inShop(fn () => (new RetailStages)->stage($key));
    }

    /** A lead that chatted once, then put in the given stage some time ago. */
    private function leadIn(string $key, array $attributes = [], ?CarbonImmutable $enteredAt = null): Lead
    {
        $this->inbound('Halo kak');

        return $this->inShop(function () use ($key, $attributes, $enteredAt) {
            $lead = Lead::query()->firstOrFail();
            $lead->forceFill($attributes + ['stage_id' => $this->shopStage($key)->id, 'stage_entered_at' => $enteredAt ?? now()])->save();

            return $lead->fresh('stage');
        });
    }

    private function fresh(Lead $lead): Lead
    {
        return $this->inShop(fn () => Lead::query()->with('stage', 'lostReason')->findOrFail($lead->id));
    }

    private function autoNote(Lead $lead): ?string
    {
        return $this->inShop(fn () => $lead->activities()->where('type', 'stage_changed')->where('body', 'like', 'Pindah otomatis%')->latest('id')->value('body'));
    }

    private function settings(array $rules): void
    {
        $this->shop->forceFill(['stage_automation' => $rules])->save();
    }

    private function sent(): array
    {
        return $this->inShop(fn () => WaMessage::query()->where('direction', 'out')->pluck('body')->all());
    }

    private function order(array $extra = []): array
    {
        return $extra + [
            'items' => [['product_id' => $this->kaos->id, 'varian' => 'Hitam L', 'jumlah' => 2]],
            'nama' => 'Sari Wulandari', 'alamat' => 'Jl. Melati No. 3, RT 02/RW 05, Tembalang', 'kota' => 'Semarang', 'provinsi' => 'Jawa Tengah',
        ];
    }

    // ---- Matching the stages ------------------------------------------------------------------------------------

    public function test_stages_are_found_by_their_template_step_even_after_being_renamed(): void
    {
        $keys = $this->inShop(fn () => Stage::query()->orderBy('position')->pluck('template_key', 'name')->all());
        $this->assertSame(['Tanya produk' => 'inquiry', 'Order masuk' => 'order', 'Menunggu pembayaran' => 'payment',
            'Dikemas & dikirim' => 'shipping', 'Selesai' => 'done', 'Batal' => 'cancelled'], $keys);

        $this->inShop(fn () => Stage::query()->where('template_key', 'payment')->update(['name' => 'Tunggu transfer']));
        $this->assertSame('Tunggu transfer', $this->shopStage(RetailStages::PAYMENT)->name);

        // An agency whose stages were never marked: names first, then the order of the open stages.
        $this->inShop(fn () => Stage::query()->update(['template_key' => null]));
        $this->inShop(fn () => Stage::query()->where('name', 'Dikemas & dikirim')->update(['name' => 'Packing']));
        $this->assertSame('Tunggu transfer', $this->shopStage(RetailStages::PAYMENT)->name);
        $this->assertSame('Packing', $this->shopStage(RetailStages::SHIPPING)->name);
        $this->assertSame('Batal', $this->shopStage(RetailStages::CANCELLED)->name);

        $this->assertEquals(['inquiry' => 1, 'order' => 2, 'payment' => 3, 'shipping' => 4, 'done' => 5, 'cancelled' => 6], RetailStages::match([
            ['id' => 1, 'name' => 'Baru', 'type' => 'open'], ['id' => 2, 'name' => 'Order masuk', 'type' => 'open'], ['id' => 3, 'name' => 'Bayar', 'type' => 'open'],
            ['id' => 4, 'name' => 'Kirim', 'type' => 'open'], ['id' => 5, 'name' => 'Beres', 'type' => 'won'], ['id' => 6, 'name' => 'Gagal', 'type' => 'lost'],
        ]));
    }

    public function test_the_migration_marks_the_stages_of_existing_shops(): void
    {
        $this->inShop(fn () => Stage::query()->update(['template_key' => null]));
        $this->inShop(fn () => Stage::query()->where('name', 'Order masuk')->update(['name' => 'Pesanan baru']));
        $migration = require database_path('migrations/2026_11_08_000100_add_stage_automation.php');
        $migration->down();
        $migration->up();

        $this->assertSame('Pesanan baru', $this->shopStage(RetailStages::ORDER)->name);
        $this->assertSame('order', $this->inShop(fn () => Stage::query()->where('name', 'Pesanan baru')->value('template_key')));
    }

    // ---- Rule 1: the AI finds a complete order before the buyer confirms ------------------------------------------

    public function test_rule_1_a_complete_order_moves_the_lead_to_order_masuk(): void
    {
        $this->channel(AiMode::Auto);
        $this->ai = [$this->toolUse('catat_pesanan', $this->order(['status' => 'draft'])), $this->text('Siap Kak, pesanannya sudah kami catat. Mau dikirim pakai kurir apa dan bayar transfer atau COD?')];

        $this->inbound('Sari Wulandari, Jl. Melati No. 3, RT 02/RW 05, Tembalang, Semarang. Kaos lari hitam L 2 pcs');

        $lead = $this->fresh($this->inShop(fn () => Lead::query()->firstOrFail()));
        $this->assertSame('Order masuk', $lead->stage->name);
        $this->assertSame('Sari Wulandari', $lead->name);
        $this->assertSame('Hitam L', $lead->variant);
        $this->assertSame(2, (int) $lead->quantity);
        $this->assertStringContainsString('RT 02/RW 05', $lead->shipping_address);
        $this->assertNull($lead->shipping_cost, 'nothing is priced before the buyer confirms');
        $this->assertSame('Pindah otomatis dari Tanya produk ke Order masuk: format order lengkap terdeteksi', $this->autoNote($lead));
    }

    public function test_rule_1_an_order_without_rt_rw_or_house_number_is_not_complete(): void
    {
        $shop = new ShopTools($this->shop);

        $this->assertSame('data_belum_lengkap', $this->inShop(fn () => $shop->run('catat_pesanan', $this->order(['status' => 'draft', 'alamat' => 'Jl. Melati, Tembalang'])))['status']);
        $this->assertStringContainsString('RT/RW', ShopTools::addressProblem('Jl. Melati No. 3, Tembalang'));
        $this->assertStringContainsString('nomor rumah atau patokan', ShopTools::addressProblem('Jl. Melati, RT 02/RW 05'));
        $this->assertNull(ShopTools::addressProblem('Jl. Melati RT 2 RW 5, depan masjid'));
        $this->assertNull(ShopTools::addressProblem('Perum Griya blok C RT03/RW05'));
        $this->assertSame('Tanyakan warna/ukuran dan jumlah setiap produk.', ShopTools::orderProblem($this->order(['items' => [['product_id' => 1, 'jumlah' => 1]]])));
        $this->assertSame('Tanyakan nama penerima.', ShopTools::orderProblem($this->order(['nama' => ''])));
        $this->assertSame([], $shop->orders());
    }

    public function test_rule_1_switched_off_keeps_the_details_but_not_the_move(): void
    {
        $this->settings(['order_draft' => ['enabled' => false]]);
        $this->channel(AiMode::Auto);
        $this->ai = [$this->toolUse('catat_pesanan', $this->order(['status' => 'draft'])), $this->text('Siap Kak, sudah dicatat.')];

        $this->inbound('Pesan kaos lari hitam L 2');

        $lead = $this->fresh($this->inShop(fn () => Lead::query()->firstOrFail()));
        $this->assertSame('Tanya produk', $lead->stage->name);
        $this->assertSame('Hitam L', $lead->variant);
    }

    // ---- Rule 2: confirmed with transfer or cash on delivery ----------------------------------------------------

    public function test_rule_2_a_confirmed_transfer_order_waits_for_payment(): void
    {
        $this->channel(AiMode::Auto);
        $this->ai = [$this->toolUse('catat_pesanan', $this->order(['status' => 'konfirmasi', 'kurir' => 'SiCepat REG', 'pembayaran' => 'transfer'])), $this->text('Pesanan dicatat, silakan transfer ya Kak.')];

        $this->inbound('Oke kak, SiCepat, transfer');

        $lead = $this->fresh($this->inShop(fn () => Lead::query()->firstOrFail()));
        $this->assertSame('Menunggu pembayaran', $lead->stage->name);
        $this->assertSame('transfer', $lead->payment_type);
        $this->assertSame('Jawa Tengah', $lead->shipping_province);
        $this->assertNotNull($lead->shipping_cost);
        $this->assertStringContainsString('bayar transfer', $this->autoNote($lead));
    }

    public function test_rule_2_cash_on_delivery_goes_straight_to_packing(): void
    {
        $this->channel(AiMode::Auto);
        $this->ai = [$this->toolUse('catat_pesanan', $this->order(['status' => 'konfirmasi', 'kurir' => 'JNE REG', 'pembayaran' => 'cod'])), $this->text('Siap Kak, pesanan COD segera kami kemas.')];

        $this->inbound('COD aja kak, JNE');

        $lead = $this->fresh($this->inShop(fn () => Lead::query()->firstOrFail()));
        $this->assertSame('Dikemas & dikirim', $lead->stage->name);
        $this->assertSame('cod', $lead->payment_type);
        $this->assertStringContainsString('bayar di tempat (COD)', $this->autoNote($lead));
    }

    public function test_rule_2_never_moves_a_lead_backwards_or_out_of_selesai(): void
    {
        $lead = $this->leadIn(RetailStages::SHIPPING);
        $this->assertNull($this->inShop(fn () => app(StageAutomation::class)->orderConfirmed($lead, 'transfer')));
        $this->assertSame('Dikemas & dikirim', $this->fresh($lead)->stage->name);

        $this->inShop(fn () => $lead->forceFill(['stage_id' => $this->shopStage(RetailStages::DONE)->id])->save());
        $this->assertNull($this->inShop(fn () => app(StageAutomation::class)->orderDrafted($this->fresh($lead))));
        $this->assertSame('Selesai', $this->fresh($lead)->stage->name);
    }

    // ---- Rule 3: payment proof ---------------------------------------------------------------------------------

    public function test_rule_3_a_picture_while_waiting_for_payment_flags_the_lead_and_tells_the_staff(): void
    {
        $lead = $this->leadIn(RetailStages::PAYMENT);

        $this->inbound('', 'image');

        $lead = $this->fresh($lead);
        $this->assertNotNull($lead->payment_proof_at);
        $this->assertSame('Menunggu pembayaran', $lead->stage->name, 'only a person confirms the payment');
        $this->assertTrue($this->inShop(fn () => $lead->activities()->where('body', 'Bukti transfer masuk, perlu dicek')->exists()));
        Mail::assertSent(PaymentProofReceived::class, fn ($mail) => $mail->hasTo($this->adminOf($this->shop)->email));

        // A picture in another stage is just a picture.
        Mail::fake();
        $this->inShop(fn () => $lead->forceFill(['stage_id' => $this->shopStage(RetailStages::INQUIRY)->id, 'payment_proof_at' => null])->save());
        $this->inbound('', 'image');
        $this->assertNull($this->fresh($lead)->payment_proof_at);
        Mail::assertNothingSent();
    }

    public function test_rule_3_the_ai_may_not_say_the_payment_arrived(): void
    {
        $this->leadIn(RetailStages::PAYMENT);
        $this->channel()->forceFill(['ai_mode' => AiMode::Auto])->save();
        $this->ai = [$this->text('Pembayaran Kakak sudah kami terima, pesanan segera dikirim ya.')];

        $message = $this->inbound('Sudah transfer ya kak');

        $draft = $this->inShop(fn () => AiDraft::query()->where('message_id', $message->id)->first());
        $this->assertSame(AiDraft::HANDOFF, $draft->status);
        $this->assertNotContains('Pembayaran Kakak sudah kami terima, pesanan segera dikirim ya.', $this->sent());
        $this->assertFalse(ShopTools::claimsPaymentReceived('Begitu pembayaran diterima, pesanan langsung kami kirim.'));
        $this->assertTrue(ShopTools::claimsPaymentReceived('Transfer sudah masuk ya Kak'));
    }

    public function test_rule_3_the_payment_received_button_moves_the_lead_to_packing(): void
    {
        $lead = $this->leadIn(RetailStages::PAYMENT, ['payment_proof_at' => now()]);
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(Inbox::class, ['leadId' => $lead->id])
            ->assertSee('Bukti transfer masuk, perlu dicek')
            ->callAction('paymentReceived');

        $lead = $this->fresh($lead);
        $this->assertSame('Dikemas & dikirim', $lead->stage->name);
        $this->assertNotNull($lead->paid_at);
        $this->assertNull($lead->payment_proof_at);

        // The same button on the lead page.
        $other = $this->inShop(fn () => Lead::create(['name' => 'Budi', 'phone' => '081299990000', 'stage_id' => $this->shopStage(RetailStages::PAYMENT)->id]));
        Livewire::test(EditLead::class, ['record' => $other->getRouteKey()])->callAction('paymentReceived');
        $this->assertSame('Dikemas & dikirim', $this->fresh($other)->stage->name);
        Livewire::test(EditLead::class, ['record' => $other->getRouteKey()])->assertActionHidden('paymentReceived');
    }

    // ---- Rule 4: tracking number -------------------------------------------------------------------------------

    public function test_rule_4_a_tracking_number_goes_to_the_buyer_and_moves_the_lead(): void
    {
        $lead = $this->leadIn(RetailStages::PAYMENT, ['paid_at' => now()]);
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(Inbox::class, ['leadId' => $lead->id])
            ->callAction('tracking', ['courier' => 'JNE REG', 'tracking_number' => 'cgk 1234567890']);

        $lead = $this->fresh($lead);
        $this->assertSame('CGK1234567890', $lead->tracking_number);
        $this->assertSame('JNE REG', $lead->courier);
        $this->assertNotNull($lead->shipped_at);
        $this->assertSame('Dikemas & dikirim', $lead->stage->name);
        $this->assertStringContainsString('nomor resi diisi', $this->autoNote($lead));
        $message = collect($this->sent())->last();
        $this->assertStringContainsString('Kurir: JNE REG', $message);
        $this->assertStringContainsString('Nomor resi: CGK1234567890', $message);
        $this->assertStringContainsString('https://www.jne.co.id/tracking-package?awb=CGK1234567890', $message);
    }

    public function test_rule_4_from_the_lead_page_and_without_an_open_chat(): void
    {
        $lead = $this->inShop(fn () => Lead::create(['name' => 'Budi', 'phone' => '081299990000', 'courier' => 'SiCepat REG', 'stage_id' => $this->shopStage(RetailStages::PAYMENT)->id]));
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->fillForm(['tracking_number' => '004123456789', 'next_action' => 'Pantau paket', 'next_action_due_at' => now()->addDay()])
            ->call('save')->assertHasNoFormErrors();

        $lead = $this->fresh($lead);
        $this->assertSame('Dikemas & dikirim', $lead->stage->name);
        $this->assertSame([], $this->sent(), 'no WhatsApp number is connected');
        $this->assertStringContainsString('belum terkirim ke pembeli', $this->inShop(fn () => $lead->activities()->where('type', 'tracking_not_sent')->value('body')));
        $this->assertSame('https://cekresi.com/?noresi=004123456789', StageAutomation::trackingUrl('SiCepat REG', '004123456789'));
    }

    // ---- Rule 5: delivered after N days ------------------------------------------------------------------------

    public function test_rule_5_shipped_parcels_finish_after_the_days_for_their_region(): void
    {
        $java = $this->leadIn(RetailStages::SHIPPING, ['shipped_at' => now()->subDays(5)->subMinute(), 'shipping_province' => 'Jawa Barat', 'deal_value' => 200000, 'variant' => 'Hitam L', 'quantity' => 2, 'property_id' => $this->kaos->id]);
        $sumatra = $this->inShop(fn () => Lead::create(['name' => 'Andi', 'phone' => '081277770000', 'stage_id' => $this->shopStage(RetailStages::SHIPPING)->id,
            'shipped_at' => now()->subDays(5)->subMinute(), 'shipping_address' => 'Jl. Sudirman 1, RT 1/2, Medan, Sumatera Utara', 'deal_value' => 150000]));

        Artisan::call('stages:automate');

        $java = $this->fresh($java);
        $this->assertSame('Selesai', $java->stage->name);
        $this->assertSame('Kaos Lari Hitam L x2', $java->unit);
        $this->assertSame('Pindah otomatis dari Dikemas & dikirim ke Selesai: 5 hari setelah nomor resi dikirim', $this->autoNote($java));
        $this->assertSame('Dikemas & dikirim', $this->fresh($sumatra)->stage->name, 'outside Java waits 8 days');

        $this->travel(3)->days();
        Artisan::call('stages:automate');
        $this->assertSame('Selesai', $this->fresh($sumatra)->stage->name);
    }

    public function test_rule_5_uses_the_days_the_shop_set(): void
    {
        $this->settings(['delivered' => ['enabled' => true, 'days_java' => 2, 'days_other' => 4]]);
        $lead = $this->leadIn(RetailStages::SHIPPING, ['shipped_at' => now()->subDays(2)->subMinute(), 'shipping_province' => 'DKI Jakarta', 'deal_value' => 1]);

        Artisan::call('stages:automate');
        $this->assertSame('Selesai', $this->fresh($lead)->stage->name);

        $this->assertTrue($this->inShop(fn () => app(StageAutomation::class)->toJava(new Lead(['shipping_address' => 'Jl. A 1, Kab. Bekasi']))));
        $this->assertFalse($this->inShop(fn () => app(StageAutomation::class)->toJava(new Lead(['shipping_address' => 'Jl. B 2, Makassar, Sulawesi Selatan 90111']))));
    }

    // ---- Rule 6: no reply, no payment -----------------------------------------------------------------------------

    public function test_rule_6_a_silent_buyer_is_cancelled_after_seven_days(): void
    {
        $lead = $this->leadIn(RetailStages::INQUIRY);
        $this->inShop(fn () => $lead->conversation->messages()->create(['direction' => 'out', 'type' => 'text', 'body' => 'Halo Kak, ada yang bisa dibantu?', 'status' => 'sent', 'sent_at' => now()]));

        $this->travel(6)->days();
        Artisan::call('stages:automate');
        $this->assertSame('Tanya produk', $this->fresh($lead)->stage->name);

        $this->travel(1)->days();
        Artisan::call('stages:automate');
        $lead = $this->fresh($lead);
        $this->assertSame('Batal', $lead->stage->name);
        $this->assertSame('Tidak ada respons', $lead->lostReason->label);
        $this->assertSame('Pindah otomatis dari Tanya produk ke Batal: tidak ada balasan pembeli 7 hari', $this->autoNote($lead));
    }

    public function test_rule_6_a_buyer_waiting_for_the_shop_is_not_cancelled(): void
    {
        $lead = $this->leadIn(RetailStages::INQUIRY);

        $this->travel(10)->days();
        Artisan::call('stages:automate');

        $this->assertSame('Tanya produk', $this->fresh($lead)->stage->name, 'the last message is the buyer\'s, unanswered');
    }

    public function test_rule_6_no_payment_three_days_after_the_last_follow_up_cancels_the_order(): void
    {
        $lead = $this->leadIn(RetailStages::PAYMENT);
        $paid = $this->inShop(fn () => Lead::create(['name' => 'Bukti', 'phone' => '081266660000', 'stage_id' => $this->shopStage(RetailStages::PAYMENT)->id, 'payment_proof_at' => now()]));
        $rule = $this->inShop(fn () => FollowUpRule::query()->where('stage_id', $this->shopStage(RetailStages::PAYMENT)->id)->firstOrFail());

        $this->travel(1)->days();
        $this->inShop(fn () => FollowUpLog::create(['follow_up_rule_id' => $rule->id, 'lead_id' => $lead->id, 'stage_id' => $lead->stage_id, 'status' => 'sent']));

        $this->travel(2)->days();
        Artisan::call('stages:automate');
        $this->assertSame('Menunggu pembayaran', $this->fresh($lead)->stage->name, 'three days count from the last follow-up');

        $this->travel(1)->days();
        Artisan::call('stages:automate');
        $lead = $this->fresh($lead);
        $this->assertSame('Batal', $lead->stage->name);
        $this->assertSame('Batal setelah order', $lead->lostReason->label);
        $this->assertStringContainsString('setelah follow-up terakhir', $this->autoNote($lead));
        $this->assertSame('Menunggu pembayaran', $this->fresh($paid)->stage->name, 'a buyer who sent proof is never cancelled');
    }

    public function test_rule_6_switched_off_does_nothing(): void
    {
        $this->settings(['no_reply' => ['enabled' => false], 'no_payment' => ['enabled' => false]]);
        $lead = $this->leadIn(RetailStages::PAYMENT);

        $this->travel(30)->days();
        Artisan::call('stages:automate');

        $this->assertSame('Menunggu pembayaran', $this->fresh($lead)->stage->name);
    }

    // ---- Rule 7: a cancelled buyer writes again --------------------------------------------------------------------

    public function test_rule_7_a_cancelled_buyer_who_writes_again_is_back_to_tanya_produk_but_selesai_stays(): void
    {
        $lead = $this->leadIn(RetailStages::CANCELLED);

        $this->inbound('Kak, masih ada stok?');
        $this->assertSame('Tanya produk', $this->fresh($lead)->stage->name);
        $this->assertSame('Pindah otomatis dari Batal ke Tanya produk: pembeli yang batal mengirim chat baru', $this->autoNote($lead));

        $this->inShop(fn () => $lead->forceFill(['stage_id' => $this->shopStage(RetailStages::DONE)->id])->save());
        $this->inbound('Kak, mau order lagi');
        $this->assertSame('Selesai', $this->fresh($lead)->stage->name);
    }

    // ---- Every move ------------------------------------------------------------------------------------------------

    public function test_an_automatic_move_starts_the_new_stage_follow_ups_and_agents_can_still_move_by_hand(): void
    {
        $lead = $this->leadIn(RetailStages::ORDER);
        $this->inShop(fn () => FollowUpRule::query()->where('stage_id', $this->shopStage(RetailStages::PAYMENT)->id)->update(['active' => true]));
        $this->inShop(fn () => app(StageAutomation::class)->orderConfirmed($lead, 'transfer'));

        $this->travel(25)->hours();
        $this->inShop(fn () => app(RunFollowUps::class)($this->shop));
        $this->assertTrue(collect($this->sent())->contains(fn ($b) => str_contains((string) $b, 'Begitu pembayaran diterima')));

        $admin = $this->adminOf($this->shop);
        $this->inShop(fn () => app(MoveLeadToStage::class)($this->fresh($lead), $this->shopStage(RetailStages::INQUIRY), $admin));
        $this->assertSame('Tanya produk', $this->fresh($lead)->stage->name);
    }

    public function test_other_industries_never_move_by_themselves(): void
    {
        $agency = $this->makeTenant('griya-prima');
        $stage = app(CurrentTenant::class)->run($agency, fn () => Stage::query()->where('type', 'lost')->first());
        $lead = app(CurrentTenant::class)->run($agency, fn () => Lead::create(['name' => 'Budi', 'phone' => '081255550000', 'stage_id' => $stage->id]));

        app(CurrentTenant::class)->run($agency, fn () => app(StageAutomation::class)->inbound($lead, new WaMessage(['type' => 'text'])));

        $this->assertSame($stage->id, app(CurrentTenant::class)->run($agency, fn () => $lead->fresh()->stage_id));
        $this->assertSame(['done' => 0, 'no_reply' => 0, 'no_payment' => 0], app(StageAutomation::class)->runScheduled($agency));
    }

    // ---- Settings ----------------------------------------------------------------------------------------------------

    public function test_a_shop_admin_switches_rules_and_sets_their_days(): void
    {
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(StageAutomationPage::class)
            ->assertSet('data.no_reply.days', 7)
            ->assertSet('data.delivered.days_java', 5)
            ->assertSet('data.delivered.days_other', 8)
            ->assertSet('data.no_payment.days', 3)
            ->fillForm(['no_reply.enabled' => false, 'no_payment.days' => 5])
            ->call('save')->assertHasNoFormErrors();

        $stored = $this->shop->fresh()->stage_automation;
        $this->assertFalse($stored['no_reply']['enabled']);
        $this->assertSame(5, $stored['no_payment']['days']);
        $this->assertTrue($stored['reopen']['enabled']);

        $agency = $this->makeTenant('griya-prima');
        $this->actingInTenant($this->adminOf($agency), $agency);
        $this->assertFalse(StageAutomationPage::canAccess(), 'only shops have stage automation');
    }
}
