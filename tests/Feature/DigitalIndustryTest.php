<?php

namespace Tests\Feature;

use App\Actions\GenerateAiReply;
use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Ai\DigitalTools;
use App\Automation\DigitalStageAutomation;
use App\Automation\DigitalStageAutomationSettings;
use App\Automation\DigitalStages;
use App\Automation\StageAutomationSettings;
use App\Enums\AiMode;
use App\Enums\Role;
use App\Enums\StageType;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\StageAutomationPage;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\LeadForms\FormTemplates;
use App\Mail\PaymentProofReceived;
use App\Models\AiDraft;
use App\Models\FollowUpRule;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\PaymentAccount;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\Support\Industry;
use App\Support\IndustryTemplate;
use App\Support\PhoneNumber;
use App\Tracking\PipelineConversions;
use App\Tutorials\Tutorials;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** The "digital" industry (Produk digital): template, stage automation, the AI's purchase tool and the payment button. */
class DigitalIndustryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $digital;

    private Property $ebook;

    /** @var list<array> Messages API responses, served in order */
    private array $ai = [];

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->digital = app(ProvisionTenant::class)('Kelas Pintar', 'kelas-pintar', User::factory()->create(), 'digital');
        $this->ebook = $this->in(fn () => Property::create(['name' => 'E-book Resep Harian', 'price_from' => 49000]));

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

    private function in(callable $fn, ?Tenant $tenant = null): mixed
    {
        return app(CurrentTenant::class)->run($tenant ?? $this->digital, $fn);
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
        return $this->in(fn () => WaChannel::query()->first() ?? WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+628990000002',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => $mode,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]), $tenant);
    }

    private function inbound(string $text, string $type = 'text', ?Tenant $tenant = null): WaMessage
    {
        $channel = $this->channel(tenant: $tenant);

        return $this->in(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6289900001234'), $type, $text === '' ? null : $text, null, CarbonImmutable::now(), 'Dina',
        )), $tenant);
    }

    private function digitalStage(string $key): Stage
    {
        return $this->in(fn () => (new DigitalStages)->stage($key));
    }

    private function leadIn(string $key, array $attributes = [], ?CarbonImmutable $enteredAt = null): Lead
    {
        $this->inbound('Halo kak');

        return $this->in(function () use ($key, $attributes, $enteredAt) {
            $lead = Lead::query()->firstOrFail();
            $lead->forceFill($attributes + ['stage_id' => $this->digitalStage($key)->id, 'stage_entered_at' => $enteredAt ?? now()])->save();

            return $lead->fresh('stage');
        });
    }

    private function fresh(Lead $lead): Lead
    {
        return $this->in(fn () => Lead::query()->with('stage', 'lostReason')->findOrFail($lead->id));
    }

    private function sent(): array
    {
        return $this->in(fn () => WaMessage::query()->where('direction', 'out')->pluck('body')->all());
    }

    /** @return list<array> the bodies sent to the Messages API */
    private function aiRequests(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.anthropic.com'))->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    // ---- Template and provisioning -----------------------------------------------------------------------------

    public function test_a_new_digital_agency_gets_the_digital_template(): void
    {
        $this->assertSame('Produk digital', Industry::options()['digital']);
        $this->assertSame('digital', $this->digital->industry);

        $stages = $this->in(fn () => Stage::query()->orderBy('position')->get());
        $this->assertSame(['Lead baru', 'Menunggu pembayaran', 'Lunas & akses terkirim', 'Tidak jadi bayar', 'Refund'], $stages->pluck('name')->all());
        $this->assertSame(['new', 'payment', 'paid', 'unpaid', 'refund'], $stages->pluck('template_key')->all());
        $this->assertSame(StageType::Won, $stages[2]->type);

        $this->assertEqualsCanonicalizing(['Harga mahal', 'Ragu fungsi atau manfaat', 'Tidak ada respons', 'Pembayaran gagal', 'Tidak cocok perangkat', 'Minta refund'],
            $this->in(fn () => LostReason::query()->pluck('label')->all()));
        $this->assertSame(3, $this->in(fn () => FollowUpRule::query()->where('active', false)->count()), 'follow-ups start switched off');
        $this->assertSame(DigitalStageAutomationSettings::defaults(), $this->digital->fresh()->stage_automation['digital']);
        $this->assertStringContainsString('refund', $this->digital->fresh()->ai_handoff_keywords);
        $this->assertStringNotContainsString('PropertyPlaybook', (string) $this->digital->fresh()->ai_instructions);

        $fields = Industry::make('digital')->leadFields();
        $this->assertArrayHasKey('access_link', $fields);
        $this->assertArrayNotHasKey('shipping_address', $fields);
        $this->assertArrayNotHasKey('courier', $fields);
        $this->assertArrayNotHasKey('shipping_cost', $fields);
    }

    public function test_the_signup_form_offers_the_digital_industry(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/app/new')->assertOk()->assertSee('Produk digital');
    }

    public function test_digital_forms_never_ask_for_a_shipping_address(): void
    {
        $templates = FormTemplates::options('digital');

        $this->assertArrayHasKey('pembelian_digital', $templates);
        $this->assertArrayNotHasKey('pesanan', $templates);
        $this->assertArrayNotHasKey(FormTemplates::ORDER, $templates);

        foreach (array_keys($templates) as $key) {
            $types = array_column(FormTemplates::attributes($key)['fields'], 'type');
            $this->assertNotContains('address', $types, $key);
        }

        // Shops and property agencies keep exactly the templates they had.
        $this->assertArrayHasKey('pesanan', FormTemplates::options('retail'));
        $this->assertArrayHasKey(FormTemplates::ORDER, FormTemplates::options('retail'));
        $this->assertArrayHasKey('pesanan', FormTemplates::options('properti'));
        $this->assertArrayNotHasKey('pembelian_digital', FormTemplates::options('retail'));
        $this->assertArrayNotHasKey('pembelian_digital', FormTemplates::options('properti'));
    }

    // ---- Switching industry ------------------------------------------------------------------------------------

    public function test_switching_property_to_digital_and_back_without_leads(): void
    {
        $agency = app(ProvisionTenant::class)('Griya Contoh', 'griya-contoh', User::factory()->create(), 'properti');

        $this->assertTrue(app(IndustryTemplate::class)->switch($agency, 'digital'));
        $this->assertSame(['Lead baru', 'Menunggu pembayaran', 'Lunas & akses terkirim', 'Tidak jadi bayar', 'Refund'],
            $this->in(fn () => Stage::query()->orderBy('position')->pluck('name')->all(), $agency));
        $this->assertTrue(DigitalStageAutomationSettings::of($agency->fresh())->enabled('no_payment'));

        $this->assertTrue(app(IndustryTemplate::class)->switch($agency->fresh(), 'properti'));
        $this->assertContains('Jadwal survei', $this->in(fn () => Stage::query()->pluck('name')->all(), $agency));
        $this->assertNotContains('Lunas & akses terkirim', $this->in(fn () => Stage::query()->pluck('name')->all(), $agency));
        $this->assertFalse(DigitalStageAutomationSettings::appliesTo($agency->fresh()));
    }

    public function test_switching_with_leads_keeps_every_lead_and_switching_back_keeps_payment_data(): void
    {
        $agency = app(ProvisionTenant::class)('Griya Contoh', 'griya-contoh', User::factory()->create(), 'properti');
        $lead = $this->in(fn () => Lead::create(['name' => 'Budi', 'phone' => '089900001111', 'stage_id' => Stage::query()->orderBy('position')->value('id')]), $agency);
        $stageBefore = $lead->stage_id;

        $this->assertFalse(app(IndustryTemplate::class)->switch($agency, 'digital'));
        $names = $this->in(fn () => Stage::query()->pluck('name')->all(), $agency);
        $this->assertContains('Jadwal survei', $names, 'nothing is removed once there are leads');
        $this->assertContains('Lunas & akses terkirim', $names);
        $this->assertSame($stageBefore, $this->in(fn () => Lead::query()->find($lead->id)->stage_id, $agency));
        $this->assertSame('paid', $this->in(fn () => Stage::query()->where('name', 'Lunas & akses terkirim')->value('template_key'), $agency));

        // Paid on the digital pipeline, then back to property: the data stays, the digital rules stop.
        $this->in(fn () => Lead::query()->find($lead->id)->forceFill(['paid_at' => now(), 'access_link' => 'https://example.com/akses', 'device' => 'Android'])->save(), $agency);
        $agency->fresh()->forceFill(['stage_automation' => ['digital' => DigitalStageAutomationSettings::defaults()]])->save();
        app(IndustryTemplate::class)->switch($agency->fresh(), 'properti');

        $kept = $this->in(fn () => Lead::query()->find($lead->id), $agency);
        $this->assertNotNull($kept->paid_at);
        $this->assertSame('https://example.com/akses', $kept->access_link);
        $this->assertSame('Android', $kept->device);
        $this->assertArrayHasKey('digital', $agency->fresh()->stage_automation, 'the digital rules are kept for a switch back');
        $this->assertFalse($this->in(fn () => app(DigitalStageAutomation::class)->applies(), $agency->fresh()));
        $this->assertNull(DigitalTools::for($agency->fresh(), $kept));
        $this->assertFalse(Industry::make('properti')->usesLeadField('access_link'));
    }

    // ---- Stage automation --------------------------------------------------------------------------------------

    public function test_a_confirmed_purchase_from_the_ai_waits_for_payment_without_any_address(): void
    {
        $this->channel(AiMode::Auto);
        $this->ai = [
            $this->toolUse(DigitalTools::RECORD, ['items' => [['product_id' => $this->ebook->id, 'jumlah' => 2]], 'status' => 'konfirmasi', 'email' => 'dina@example.com', 'perangkat' => 'Android']),
            $this->text('Siap Kak, pembelian E-book Resep Harian x2 dicatat, totalnya Rp 98.000. Silakan transfer ya.'),
        ];

        $this->inbound('Oke kak saya ambil 2, email dina@example.com, pakai Android');

        $lead = $this->fresh($this->in(fn () => Lead::query()->firstOrFail()));
        $this->assertSame('Menunggu pembayaran', $lead->stage->name);
        $this->assertSame($this->ebook->id, $lead->property_id);
        $this->assertSame(2, (int) $lead->quantity);
        $this->assertSame(98000, (int) $lead->deal_value);
        $this->assertSame('dina@example.com', $lead->email);
        $this->assertSame('Android', $lead->device);
        $this->assertSame('transfer', $lead->payment_type);
        $this->assertNull($lead->shipping_address);
        $this->assertNull($lead->shipping_cost);
        $this->assertNull($lead->courier);
        $this->assertNull($lead->paid_at, 'only a person marks a purchase paid');
        $this->assertTrue($this->in(fn () => $lead->activities()->where('type', 'purchase_recorded')->exists()));

        // The model saw the digital tool only, and a prompt that forbids asking for an address.
        $request = $this->aiRequests()[0];
        $tools = array_column($request['tools'], 'name');
        $this->assertContains(DigitalTools::RECORD, $tools);
        $this->assertNotContains('catat_pesanan', $tools);
        $this->assertNotContains('cek_ongkir', $tools);
        $system = is_array($request['system']) ? implode("\n", array_column($request['system'], 'text')) : $request['system'];
        $this->assertStringContainsString('JANGAN menanyakan alamat, kota, kurir, atau ongkir', $system);
        $this->assertStringContainsString('E-book Resep Harian', $system);
    }

    public function test_a_draft_purchase_records_the_product_but_does_not_move(): void
    {
        $this->channel(AiMode::Auto);
        $this->ai = [$this->toolUse(DigitalTools::RECORD, ['items' => [['produk' => 'e-book resep']], 'status' => 'draft']), $this->text('E-book Resep Harian Rp 49.000 ya Kak, jadi ambil?')];

        $this->inbound('Mau e-book resep kak');

        $lead = $this->fresh($this->in(fn () => Lead::query()->firstOrFail()));
        $this->assertSame('Lead baru', $lead->stage->name);
        $this->assertSame($this->ebook->id, $lead->property_id);
        $this->assertNull($lead->payment_type);
        $this->assertTrue($this->in(fn () => $lead->activities()->where('type', 'purchase_draft')->exists()));
    }

    public function test_the_tool_prices_from_the_catalogue_only(): void
    {
        $lead = $this->leadIn(DigitalStages::NEW);
        $tools = DigitalTools::for($this->digital, $lead);

        $result = $this->in(fn () => $tools->run(DigitalTools::RECORD, ['items' => [['product_id' => $this->ebook->id, 'jumlah' => 3, 'harga' => 1]], 'status' => 'konfirmasi']));
        $this->assertSame(147000, $result['total']);
        $this->assertSame('produk_belum_jelas', $this->in(fn () => $tools->run(DigitalTools::RECORD, ['items' => [['produk' => 'Tidak ada']], 'status' => 'draft']))['status']);

        $free = $this->in(fn () => Property::create(['name' => 'Kelas Tanpa Harga']));
        $unpriced = $this->in(fn () => $tools->run(DigitalTools::RECORD, ['items' => [['product_id' => $free->id]], 'status' => 'konfirmasi']));
        $this->assertArrayNotHasKey('total', $unpriced);
        $this->assertStringContainsString('jangan menyebut total', $unpriced['catatan']);
    }

    public function test_with_bank_accounts_the_system_sends_the_transfer_instructions(): void
    {
        $this->in(fn () => PaymentAccount::create(['bank_name' => 'BCA', 'account_number' => '1234567890', 'account_holder' => 'Kelas Pintar', 'active' => true, 'sort_order' => 1]));
        $this->channel(AiMode::Auto);
        $this->ai = [$this->toolUse(DigitalTools::RECORD, ['items' => [['product_id' => $this->ebook->id]], 'status' => 'konfirmasi']), $this->text('Pembelian dicatat, totalnya Rp 49.000. Instruksi bayar menyusul ya Kak.')];

        $this->inbound('Jadi beli kak');

        $lead = $this->fresh($this->in(fn () => Lead::query()->firstOrFail()));
        $this->assertSame('Menunggu pembayaran', $lead->stage->name);
        $this->assertNotNull($lead->payment_amount);
        $this->assertTrue(collect($this->sent())->contains(fn ($b) => str_contains((string) $b, '1234567890')), 'the account number comes from the system');
    }

    public function test_a_picture_while_waiting_for_payment_flags_the_lead_for_a_person(): void
    {
        $lead = $this->leadIn(DigitalStages::PAYMENT);

        $this->inbound('', 'image');

        $lead = $this->fresh($lead);
        $this->assertNotNull($lead->payment_proof_at);
        $this->assertNull($lead->paid_at);
        $this->assertSame('Menunggu pembayaran', $lead->stage->name);
        Mail::assertSent(PaymentProofReceived::class, fn ($mail) => $mail->hasTo($this->adminOf($this->digital)->email)
            && str_contains($mail->render(), 'Lunas &amp; akses terkirim'));
    }

    public function test_the_ai_may_not_say_the_payment_arrived(): void
    {
        $this->leadIn(DigitalStages::PAYMENT);
        $this->channel()->forceFill(['ai_mode' => AiMode::Auto])->save();
        $this->ai = [$this->text('Pembayaran Kakak sudah kami terima, akses segera aktif.'), $this->text('Transfer sudah masuk ya Kak, sudah lunas.')];

        $message = $this->inbound('Sudah transfer kak');

        $draft = $this->in(fn () => AiDraft::query()->where('message_id', $message->id)->first());
        $this->assertStringContainsString('pembayaran harus dicek agen', $draft->reason);
        $this->assertSame([GenerateAiReply::SAFE_DIGITAL_PAYMENT_REPLY], array_slice($this->sent(), -1));
        $this->assertNull($this->fresh($this->in(fn () => Lead::query()->firstOrFail()))->paid_at);
    }

    public function test_payment_received_moves_to_lunas_and_sends_the_access_link(): void
    {
        $lead = $this->leadIn(DigitalStages::PAYMENT, ['payment_proof_at' => now(), 'property_id' => $this->ebook->id, 'quantity' => 2]);
        $this->actingInTenant($this->adminOf($this->digital), $this->digital);

        Livewire::test(Inbox::class, ['leadId' => $lead->id])
            ->assertActionHidden('paymentReceived')
            ->callAction('digitalPaymentReceived', ['access_link' => 'https://example.com/unduh/abc', 'send' => true]);

        $lead = $this->fresh($lead);
        $this->assertSame('Lunas & akses terkirim', $lead->stage->name);
        $this->assertNotNull($lead->paid_at);
        $this->assertNull($lead->payment_proof_at);
        $this->assertSame('https://example.com/unduh/abc', $lead->access_link);
        $this->assertSame('E-book Resep Harian x2', $lead->unit);
        $this->assertSame(98000, (int) $lead->deal_value);
        $this->assertStringContainsString('https://example.com/unduh/abc', (string) collect($this->sent())->last());
        $this->assertTrue($this->in(fn () => $lead->activities()->where('type', 'payment_received')->exists()));

        // The same button on the lead page, without a link: paid, nothing sent.
        $other = $this->in(fn () => Lead::create(['name' => 'Budi', 'phone' => '089900002222', 'stage_id' => $this->digitalStage(DigitalStages::PAYMENT)->id, 'property_id' => $this->ebook->id]));
        $count = count($this->sent());
        Livewire::test(EditLead::class, ['record' => $other->getRouteKey()])->callAction('digitalPaymentReceived', ['access_link' => null]);
        $this->assertSame('Lunas & akses terkirim', $this->fresh($other)->stage->name);
        $this->assertCount($count, $this->sent());
        Livewire::test(EditLead::class, ['record' => $other->getRouteKey()])->assertActionHidden('digitalPaymentReceived');
    }

    public function test_an_unpaid_purchase_falls_to_tidak_jadi_bayar_after_the_hours(): void
    {
        $late = $this->leadIn(DigitalStages::PAYMENT, [], CarbonImmutable::now()->subHours(49));
        $other = $this->in(fn () => Lead::create(['name' => 'Budi', 'phone' => '089900003333', 'stage_id' => $this->digitalStage(DigitalStages::PAYMENT)->id, 'stage_entered_at' => now()->subHours(49), 'payment_proof_at' => now()]));
        $recent = $this->in(fn () => Lead::create(['name' => 'Cici', 'phone' => '089900004444', 'stage_id' => $this->digitalStage(DigitalStages::PAYMENT)->id, 'stage_entered_at' => now()->subHours(2)]));

        Artisan::call('stages:automate');

        $late = $this->fresh($late);
        $this->assertSame('Tidak jadi bayar', $late->stage->name);
        $this->assertSame('Pembayaran gagal', $late->lostReason->label);
        $this->assertStringContainsString('belum ada pembayaran 48 jam', $this->in(fn () => $late->activities()->where('type', 'stage_changed')->latest('id')->value('body')));
        $this->assertSame('Menunggu pembayaran', $this->fresh($other)->stage->name, 'a lead that sent a proof waits for a person');
        $this->assertSame('Menunggu pembayaran', $this->fresh($recent)->stage->name);

        // Writing again starts over.
        $this->inbound('Kak masih bisa beli?');
        $this->assertSame('Lead baru', $this->fresh($late)->stage->name);
    }

    public function test_the_rules_can_be_switched_off_and_are_kept_under_their_own_key(): void
    {
        $this->actingInTenant($this->adminOf($this->digital), $this->digital);
        $state = DigitalStageAutomationSettings::defaults();
        $state['no_payment'] = ['enabled' => true, 'hours' => 6];
        $state['reopen']['enabled'] = false;

        Livewire::test(StageAutomationPage::class)->set('data', $state)->call('save')->assertHasNoErrors();

        $stored = $this->digital->fresh()->stage_automation;
        $this->assertSame(6, $stored['digital']['no_payment']['hours']);
        $this->assertFalse($stored['digital']['reopen']['enabled']);
        $this->assertFalse(StageAutomationSettings::appliesTo($this->digital->fresh()));

        $lead = $this->leadIn(DigitalStages::PAYMENT, [], CarbonImmutable::now()->subHours(7));
        Artisan::call('stages:automate');
        $this->assertSame('Tidak jadi bayar', $this->fresh($lead)->stage->name);
        $this->inbound('Halo lagi kak');
        $this->assertSame('Tidak jadi bayar', $this->fresh($lead)->stage->name, 'reopen is off');
    }

    public function test_meta_events_map_the_digital_stages(): void
    {
        $map = $this->in(fn () => PipelineConversions::defaults('digital'));

        $this->assertSame([$this->digitalStage(DigitalStages::PAYMENT)->id => 'InitiateCheckout', $this->digitalStage(DigitalStages::PAID)->id => 'Purchase'], $map['stages']);
        $this->assertSame('Lead', $map['hot']);
        $this->assertFalse($map['enabled']);
    }

    public function test_the_tutorial_has_digital_chapters_only_for_digital_agencies(): void
    {
        $slugs = fn (Tenant $t) => Tutorials::visible($t->industry(), Role::Admin)->keys()->all();
        $shop = app(ProvisionTenant::class)('Toko Contoh', 'toko-contoh', User::factory()->create(), 'retail');

        $this->assertContains('pipeline-digital', $slugs($this->digital));
        $this->assertContains('otomatisasi-tahap-digital', $slugs($this->digital));
        $this->assertNotContains('pipeline', $slugs($this->digital), 'the shop board is not the digital one');
        $this->assertNotContains('cek-ongkir', $slugs($this->digital));
        $this->assertNotContains('pipeline-digital', $slugs($shop));

        $this->actingInTenant($this->adminOf($this->digital), $this->digital);
        $this->get('/app/'.$this->digital->slug.'/tutorial?bab=pipeline-digital')->assertOk()->assertSee('Pembayaran diterima');
    }

    // ---- Isolation ---------------------------------------------------------------------------------------------

    public function test_digital_never_reaches_shops_or_property_agencies(): void
    {
        $shop = app(ProvisionTenant::class)('Toko Contoh', 'toko-contoh', User::factory()->create(), 'retail');
        $agency = app(ProvisionTenant::class)('Griya Contoh', 'griya-contoh', User::factory()->create(), 'properti');

        foreach ([$shop, $agency] as $tenant) {
            $lead = $this->in(fn () => Lead::create(['name' => 'Budi', 'phone' => '089900005555', 'stage_id' => Stage::query()->value('id')]), $tenant);
            $this->assertNull(DigitalTools::for($tenant, $lead));
            $this->assertFalse($this->in(fn () => app(DigitalStageAutomation::class)->applies(), $tenant));
            $this->assertFalse($this->in(fn () => app(DigitalStageAutomation::class)->canConfirmPayment($lead), $tenant));
            $this->assertArrayNotHasKey('digital', (array) $tenant->fresh()->stage_automation);
            $this->assertFalse($tenant->industry()->usesLeadField('access_link'));
        }

        // The scheduler leaves them alone: a shop lead waiting for payment is moved by the shop rules only.
        $this->assertSame(['no_payment' => 0, 'reminded' => 0], app(DigitalStageAutomation::class)->runScheduled($shop));
        $this->assertSame(['no_payment' => 0, 'reminded' => 0], app(DigitalStageAutomation::class)->runScheduled($agency));
    }
}
