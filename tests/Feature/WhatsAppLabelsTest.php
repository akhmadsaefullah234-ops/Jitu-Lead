<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\MoveLeadToStage;
use App\Actions\ProvisionTenant;
use App\Enums\AiMode;
use App\Enums\Interest;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Resources\WaChannels\Pages\EditWaChannel;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaLabelEvent;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\BusinessLabels;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Trial: WhatsApp Business labels ("Prospek", "Order baru", "Lunas") put on a lead's chat through the QR gateway
 * when it turns Hot or moves along the pipeline: once per lead and label, in order, off until switched on.
 */
class WhatsAppLabelsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private WaChannel $gw;

    private int $n = 0;

    /** What the gateway answers to a chat label: status and body. */
    private array $answer = [200, ['label' => ['id' => '5', 'name' => 'x'], 'already' => false]];

    /** The account's labels, as the gateway reports them. */
    private array $accountLabels = [['id' => '5', 'name' => 'Prospek', 'color' => 1], ['id' => '7', 'name' => 'Order baru', 'color' => 2]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'gw.example.com/sessions/*/chat-labels' => fn () => Http::response($this->answer[1], $this->answer[0]),
            'gw.example.com/sessions/*/labels/resync' => fn () => Http::response(['labels' => $this->accountLabels, 'resynced' => true]),
            'gw.example.com/sessions/*/labels' => fn () => Http::response(['labels' => $this->accountLabels]),
            '*' => Http::response(['id' => 'x']),
        ]);
        $this->gw = $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002', 'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Off,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
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

    private function enable(array $settings = []): void
    {
        $this->in(fn () => $this->gw->forceFill(['label_settings' => $settings + ['enabled' => true] + BusinessLabels::defaults('retail')])->save());
    }

    /** A chat on the gateway number; from a Click-to-WhatsApp ad unless said. */
    private function chat(string $phone = '081234567890', bool $fromAd = true, ?WaChannel $channel = null): Lead
    {
        $this->in(fn () => app(HandleInboundWhatsApp::class)->message($channel ?? $this->gw, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize($phone), 'text', 'Halo kak', null, CarbonImmutable::now(), 'Rina',
            $fromAd ? ['source_id' => '120210000000001', 'headline' => 'Raket', 'ctwa_clid' => 'clid-'.$this->n] : null,
        )));

        return $this->in(fn () => Lead::query()->where('phone', PhoneNumber::normalize($phone))->firstOrFail());
    }

    private function move(Lead $lead, string $stage): void
    {
        $this->in(fn () => app(MoveLeadToStage::class)($lead->fresh(), $this->stage($this->shop, $stage), $this->adminOf($this->shop), ['unit' => 'Raket', 'deal_value' => 349000]));
    }

    private function hot(Lead $lead, bool $hot = true): void
    {
        $this->in(fn () => $lead->fresh()->update(['interest' => $hot ? Interest::Hot : Interest::Warm]));
    }

    /** @return list<array{phone: string, label: string}> the labels asked of the gateway, in order */
    private function labelled(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/chat-labels'))->map(fn ($p) => ['phone' => $p[0]['phone'], 'label' => $p[0]['label']])->values()->all();
    }

    private function notes(Lead $lead): array
    {
        return $this->in(fn () => $lead->activities()->where('type', 'note')->where('body', 'like', 'Label WhatsApp%')->oldest('id')->pluck('body')->all());
    }

    public function test_it_is_off_by_default(): void
    {
        $lead = $this->chat();
        $this->hot($lead);
        $this->move($lead, 'Order masuk');

        $this->assertSame([], $this->labelled());
        $this->assertSame(0, $this->in(fn () => WaLabelEvent::query()->count()));
    }

    public function test_hot_puts_prospek_on_once_through_the_chats_gateway(): void
    {
        $this->enable();
        $lead = $this->chat();

        $this->hot($lead);
        $this->hot($lead, false);
        $this->hot($lead);

        $this->assertSame([['phone' => '+6281234567890', 'label' => 'Prospek']], $this->labelled());
        $this->assertStringContainsString('/sessions/'.$this->gw->webhook_token.'/chat-labels', Http::recorded(fn (Request $r) => str_contains($r->url(), '/chat-labels'))->first()[0]->url());
        $this->assertSame(["Label WhatsApp 'Prospek' dipasang (uji coba)"], $this->notes($lead));
        $this->assertSame('sent', $this->in(fn () => WaLabelEvent::query()->sole()->status));
    }

    public function test_a_jump_to_the_paid_stage_puts_the_earlier_labels_on_first_and_none_twice(): void
    {
        $this->enable();
        $lead = $this->chat();

        $this->move($lead, 'Dikemas & dikirim');
        $this->move($lead, 'Order masuk');
        $this->hot($lead);
        $this->move($lead, 'Selesai');

        $this->assertSame(['Prospek', 'Order baru', 'Lunas'], array_column($this->labelled(), 'label'));
        $this->assertSame(3, $this->in(fn () => WaLabelEvent::query()->where('status', 'sent')->count()));
    }

    public function test_paid_follows_the_payment_cash_on_delivery_at_selesai_transfer_at_dikemas(): void
    {
        $this->enable();
        $cod = $this->chat('081200000001');
        $transfer = $this->chat('081200000002');
        $this->in(fn () => $cod->fresh()->update(['payment_type' => 'cod']));
        $this->in(fn () => $transfer->fresh()->update(['payment_type' => 'transfer']));

        $this->move($cod, 'Order masuk');
        $this->move($cod, 'Dikemas & dikirim');
        $this->move($transfer, 'Order masuk');
        $this->move($transfer, 'Dikemas & dikirim');

        $byPhone = fn (string $phone) => array_column(array_filter($this->labelled(), fn ($l) => $l['phone'] === PhoneNumber::normalize($phone)), 'label');
        $this->assertSame(['Prospek', 'Order baru'], $byPhone('081200000001'), 'COD is not paid when it is shipped');
        $this->assertSame(['Prospek', 'Order baru', 'Lunas'], $byPhone('081200000002'));

        $this->move($cod, 'Selesai');
        $this->assertSame(['Prospek', 'Order baru', 'Lunas'], $byPhone('081200000001'));
    }

    public function test_only_chats_from_ads_by_default_the_rest_are_skipped_and_noted(): void
    {
        $this->enable();
        $lead = $this->chat('081299990000', fromAd: false);

        $this->hot($lead);

        $this->assertSame([], $this->labelled());
        $this->assertSame(['skipped', 'not_from_ad'], $this->in(fn () => [WaLabelEvent::query()->sole()->status, WaLabelEvent::query()->sole()->error]));
        $this->assertSame(["Label WhatsApp 'Prospek' dilewati (uji coba): chat bukan dari iklan."], $this->notes($lead));

        $this->enable(['ads_only' => false]);
        $this->move($lead, 'Order masuk');
        $this->assertSame(['Order baru'], array_column($this->labelled(), 'label'), 'Prospek stays skipped: once per lead');
    }

    public function test_a_missing_label_waits_and_a_personal_account_fails_both_noted_with_what_to_do(): void
    {
        $this->enable();
        $lead = $this->chat('081200000003');
        $this->answer = [422, ['error' => 'label_not_found']];
        $this->hot($lead);

        $other = $this->chat('081200000004');
        $this->answer = [422, ['error' => 'not_business']];
        $this->hot($other);

        $this->assertStringContainsString('menunggu (uji coba): label itu belum ada di WhatsApp Business. Pasang label itu sekali di HP', $this->notes($lead)[0]);
        $this->assertStringContainsString('bukan akun WhatsApp Business', $this->notes($other)[0]);
        $this->assertSame([['waiting_label', 'label_not_found'], ['failed', 'not_business']], $this->in(fn () => WaLabelEvent::query()->orderBy('id')->get()->map(fn ($e) => [$e->status, $e->error])->all()));
    }

    public function test_a_waiting_label_goes_on_once_the_account_has_it_and_fails_after_7_days(): void
    {
        $this->enable();
        $this->answer = [422, ['error' => 'label_not_found']];
        $lead = $this->chat('081200000006');
        $this->move($lead, 'Dikemas & dikirim');
        $late = $this->chat('081200000007');
        $this->hot($late);
        $this->assertSame(['waiting_label', 'waiting_label', 'waiting_label', 'waiting_label'], $this->in(fn () => WaLabelEvent::query()->orderBy('id')->pluck('status')->all()));

        // Still missing on the account: nothing is asked again, nothing noted twice.
        $this->accountLabels = [];
        $this->artisan('whatsapp:labels-retry')->assertSuccessful();
        $this->assertCount(4, $this->labelled());

        // The account now has "Lead" (= Prospek) and "Order baru", not "Lunas".
        $this->accountLabels = [['id' => '5', 'name' => 'Lead'], ['id' => '7', 'name' => 'Order baru']];
        $this->answer = [200, ['label' => ['id' => '5', 'name' => 'Lead'], 'already' => false]];
        $this->artisan('whatsapp:labels-retry')->assertSuccessful();
        $this->assertSame(['Prospek', 'Order baru', 'Prospek'], array_column(array_slice($this->labelled(), 4), 'label'), 'in order, only the ones the account has');
        $this->assertSame(['sent', 'sent', 'waiting_label', 'sent'], $this->in(fn () => WaLabelEvent::query()->orderBy('id')->pluck('status')->all()));
        $this->assertEqualsCanonicalizing(["Label WhatsApp 'Prospek' menunggu", "Label WhatsApp 'Order baru' menunggu", "Label WhatsApp 'Lunas' menunggu", "Label WhatsApp 'Prospek' dipasang", "Label WhatsApp 'Order baru' dipasang"], array_map(fn ($n) => explode(' (', $n)[0], $this->notes($lead)), 'the wait is noted once');

        $this->travel(8)->days();
        $this->artisan('whatsapp:labels-retry')->assertSuccessful();
        $this->assertSame(['failed', 'label_not_found'], $this->in(fn () => [WaLabelEvent::query()->where('label', 'Lunas')->sole()->status, WaLabelEvent::query()->where('label', 'Lunas')->sole()->error]));
        $this->assertCount(1, array_filter($this->notes($lead), fn ($n) => str_contains($n, "'Lunas' gagal dipasang (uji coba): setelah 7 hari menunggu")));
    }

    public function test_a_gateway_failure_never_stops_the_stage_move_and_logs_no_phone_number(): void
    {
        $this->enable();
        $lead = $this->chat();
        $this->answer = [503, ['error' => 'Sesi WhatsApp belum terhubung.']];
        Log::spy();

        $this->move($lead, 'Order masuk');

        $this->assertSame($this->stage($this->shop, 'Order masuk')->getKey(), $lead->fresh()->stage_id);
        $this->assertSame(['failed', 'failed'], $this->in(fn () => WaLabelEvent::query()->orderBy('id')->pluck('status')->all()));
        $this->assertCount(1, $this->labelled(), 'it stops at the first label so the order is kept');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'WhatsApp label not put on' && ! str_contains(json_encode($context), '81234567890'));
    }

    public function test_chats_on_the_cloud_api_are_left_alone(): void
    {
        $official = $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Official, 'name' => 'API', 'phone' => '+6281100000001', 'status' => WaChannelStatus::Connected, 'position' => 2, 'ai_mode' => AiMode::Off,
            'credentials' => ['phone_number_id' => '555', 'access_token' => 't', 'app_secret' => 's', 'verify_token' => 'v'],
            'label_settings' => ['enabled' => true, 'ads_only' => false] + BusinessLabels::defaults('retail'),
        ]));
        $lead = $this->chat('081200000005', true, $official);

        $this->hot($lead);

        $this->assertSame([], $this->labelled());
        $this->assertSame(0, $this->in(fn () => WaLabelEvent::query()->count()));
    }

    public function test_property_defaults_survey_booking_and_closing(): void
    {
        $agency = $this->makeTenant('griya-uji');
        $rules = app(CurrentTenant::class)->run($agency, fn () => BusinessLabels::defaults('properti'))['rules'];

        $this->assertSame([true, [$this->stage($agency, 'Jadwal survei')->getKey()]], [$rules[0]['hot'], $rules[0]['stages']]);
        $this->assertSame([$this->stage($agency, 'Booking')->getKey()], $rules[1]['stages']);
        $this->assertSame([$this->stage($agency, 'Closing')->getKey()], $rules[2]['stages']);
    }

    public function test_the_channel_page_loads_labels_saves_the_settings_and_shows_the_summary(): void
    {
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        $selesai = Stage::query()->where('name', 'Selesai')->value('id');

        Livewire::test(EditWaChannel::class, ['record' => $this->gw->getKey()])
            ->assertFormSet(['label_settings.enabled' => false, 'label_settings.ads_only' => true, 'label_settings.rules.0.name' => 'Prospek', 'label_settings.rules.0.hot' => true])
            ->callAction(TestAction::make('loadLabels')->schemaComponent('wa_labels', 'form'))
            ->assertNotified('Ada label yang belum ada di WhatsApp')
            ->assertSee('belum ada: pasang sekali di HP')
            ->fillForm(['label_settings.enabled' => true, 'label_settings.rules.2.name' => 'Paid', 'label_settings.rules.2.stages' => [$selesai]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSee('Belum ada label yang dipasang');

        $settings = BusinessLabels::settings($this->gw->fresh());
        $this->assertSame([true, 'Paid', [$selesai], ['Prospek', 'Order baru']], [$settings['enabled'], $settings['rules'][2]['name'], $settings['rules'][2]['stages'], $settings['known']]);
    }

    public function test_load_labels_resyncs_ticks_the_three_labels_and_puts_on_the_waiting_ones(): void
    {
        $this->enable();
        $this->answer = [422, ['error' => 'label_not_found']];
        $lead = $this->chat('081200000008');
        $this->hot($lead);
        $this->assertSame('waiting_label', $this->in(fn () => WaLabelEvent::query()->sole()->status));

        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        $this->answer = [200, ['label' => ['id' => '5', 'name' => 'Lead'], 'already' => false]];
        $this->accountLabels = [['id' => '5', 'name' => 'Lead'], ['id' => '7', 'name' => 'New order'], ['id' => '9', 'name' => 'Lunas']];

        Livewire::test(EditWaChannel::class, ['record' => $this->gw->getKey()])
            ->callAction(TestAction::make('loadLabels')->schemaComponent('wa_labels', 'form'))
            ->assertNotified('Semua label tersedia')
            ->assertSee('(sebagai "Lead")', false);

        $this->assertTrue(Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/sessions/'.$this->gw->webhook_token.'/labels/resync'))->isNotEmpty());
        $this->assertSame('sent', $this->in(fn () => WaLabelEvent::query()->sole()->status));
        $this->assertSame(['Prospek' => 'Lead', 'Order baru' => 'New order', 'Lunas' => 'Lunas'], BusinessLabels::checklist($this->gw->fresh()));
    }
}
