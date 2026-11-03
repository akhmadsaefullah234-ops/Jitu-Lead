<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\MoveLeadToStage;
use App\Actions\ProvisionTenant;
use App\Actions\RegisterLead;
use App\Enums\AiMode;
use App\Enums\Interest;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\TrackingSettings;
use App\Jobs\SendConversionEvents;
use App\Models\ConversionEvent;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Models\User;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\Tracking\PipelineConversions;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Conversion events from the CRM: a stage the agency mapped, or a lead turning Hot, reports an event to Meta (and
 * TikTok) once per lead, hashed, as "system_generated"; the business messaging route only for Cloud API chats
 * that came from a Click-to-WhatsApp ad.
 */
class PipelineConversionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private int $n = 0;

    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'graph.facebook.com/*/dataset*' => Http::response(['data' => [['id' => '777000111']]]),
            '*' => fn () => $this->status === 200 ? Http::response(['events_received' => 1]) : Http::response(['error' => ['message' => 'Invalid token']], $this->status),
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

    /** Meta and TikTok connected; the pipeline mapping given (on unless said). */
    private function tracking(array $pipeline = [], array $credentials = []): TrackingSetting
    {
        return $this->in(fn () => TrackingSetting::create([
            'meta_pixel_id' => '1234567890', 'tiktok_pixel_id' => 'CABC123XYZ',
            'credentials' => $credentials + ['meta_capi_token' => 'meta-secret', 'tiktok_events_token' => 'tt-secret'],
            'pipeline_events' => $pipeline + ['enabled' => true, 'hot' => 'Lead', 'stages' => [$this->stageId('Order masuk') => 'InitiateCheckout', $this->stageId('Dikemas & dikirim') => 'Purchase']],
        ]));
    }

    private function stageId(string $name): int
    {
        return $this->stage($this->shop, $name)->getKey();
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->in(fn () => Lead::create($attributes + [
            'name' => 'Rina Putri Ayu', 'phone' => '0812-3456-7890', 'email' => ' Rina@Contoh.ID ',
            'stage_id' => $this->stageId('Tanya produk'), 'interest' => Interest::Warm,
        ]));
    }

    private function move(Lead $lead, string $stage): void
    {
        $this->in(fn () => app(MoveLeadToStage::class)($lead->fresh(), Stage::query()->findOrFail($this->stageId($stage)), $this->adminOf($this->shop)));
    }

    /** @return list<array<string, mixed>> the events sent to Meta's pixel, in order */
    private function metaEvents(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/1234567890/events'))->map(fn ($pair) => $pair[0]['data'][0])->values()->all();
    }

    // ---- When an event goes ------------------------------------------------------------------------------------

    public function test_entering_a_mapped_stage_queues_the_event_once_per_lead(): void
    {
        $this->tracking();
        $lead = $this->lead();
        Queue::fake();

        $this->move($lead, 'Order masuk');
        $this->move($lead, 'Tanya produk');
        $this->move($lead, 'Order masuk');

        Queue::assertPushed(SendConversionEvents::class, 1);
        Queue::assertPushed(SendConversionEvents::class, function (SendConversionEvents $job) use ($lead) {
            $p = $job->payload;

            return $p['eventId'] === 'lead-'.$lead->getKey().'-InitiateCheckout' && $p['event']['meta'] === 'InitiateCheckout'
                && $p['extra']['action_source'] === 'system_generated' && $p['extra']['trigger'] === 'stage'
                && $p['eventTime'] === $lead->fresh()->activities()->where('type', 'stage_changed')->oldest('id')->first()->created_at->getTimestamp();
        });
        $this->assertSame(1, $this->in(fn () => ConversionEvent::query()->count()));
    }

    public function test_hot_sends_lead_and_an_unmapped_stage_or_a_switched_off_pipeline_sends_nothing(): void
    {
        $setting = $this->tracking();
        $lead = $this->lead();
        Queue::fake();

        $this->move($lead, 'Menunggu pembayaran');
        Queue::assertNothingPushed();

        $this->in(fn () => $lead->fresh()->update(['interest' => Interest::Hot]));
        Queue::assertPushed(SendConversionEvents::class, fn (SendConversionEvents $job) => $job->payload['event']['meta'] === 'Lead' && $job->payload['extra']['trigger'] === 'hot');
        $this->in(fn () => $lead->fresh()->update(['interest' => Interest::Warm]));
        $this->in(fn () => $lead->fresh()->update(['interest' => Interest::Hot]));
        Queue::assertPushed(SendConversionEvents::class, 1);

        $this->in(fn () => $setting->update(['pipeline_events' => ['enabled' => false] + $setting->pipeline()]));
        $this->move($lead, 'Order masuk');
        Queue::assertPushed(SendConversionEvents::class, 1);
    }

    public function test_a_new_whatsapp_lead_in_a_mapped_stage_is_reported_but_a_web_form_lead_is_not_counted_twice(): void
    {
        $this->tracking(['stages' => [$this->stageId('Tanya produk') => 'Contact']]);
        $gw = $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002', 'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Off,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
        Queue::fake();

        $this->in(fn () => app(HandleInboundWhatsApp::class)->message($gw, new InboundMessage('in-1', '+6281299990000', 'text', 'Halo', null, CarbonImmutable::now(), 'Budi')));
        Queue::assertPushed(SendConversionEvents::class, fn (SendConversionEvents $job) => $job->payload['event']['meta'] === 'Contact');

        $this->in(fn () => app(RegisterLead::class)(['name' => 'Sari', 'phone' => '081277778888'], 'Formulir web', 'form'));
        Queue::assertPushed(SendConversionEvents::class, 1);
    }

    // ---- What it carries ---------------------------------------------------------------------------------------

    public function test_the_event_is_hashed_the_meta_way_and_carries_the_order_value_without_raw_personal_data(): void
    {
        $this->tracking();
        [$raket, $tas] = $this->in(fn () => [
            Property::create(['name' => 'Raket Capra Pro', 'price_from' => 349000]),
            Property::create(['name' => 'Tas Selempang Sport', 'price_from' => 129000]),
        ]);
        $lead = $this->lead([
            'order_items' => [
                ['product_id' => $raket->getKey(), 'name' => 'Raket Capra Pro', 'variant' => 'Merah', 'quantity' => 2, 'price' => 349000],
                ['product_id' => $tas->getKey(), 'name' => 'Tas Selempang Sport', 'variant' => null, 'quantity' => 1, 'price' => 129000],
            ],
            'shipping_cost' => 25000,
            'shipping_address_parts' => ['street' => 'Jl. Mawar 5', 'kota' => 'Kab. Bekasi', 'provinsi' => 'Jawa Barat', 'kode_pos' => '17510'],
            'custom_fields' => ['iklan' => ['ctwa_clid' => 'ARAkLk-clid'], 'pelacakan' => ['fbc' => 'fb.1.1700000000.AbC', 'fbp' => 'fb.1.1700000000.123']],
            'ad_id' => '120210000000001',
        ]);
        Log::spy();

        $this->move($lead, 'Dikemas & dikirim');

        $event = $this->metaEvents()[0];
        $user = $event['user_data'];
        $this->assertSame('Purchase', $event['event_name']);
        $this->assertSame('system_generated', $event['action_source']);
        $this->assertArrayNotHasKey('event_source_url', $event);
        $this->assertSame([hash('sha256', '6281234567890')], $user['ph'], 'phone as 62..., digits only');
        $this->assertSame([hash('sha256', 'rina@contoh.id')], $user['em']);
        $this->assertSame(hash('sha256', 'rina'), $user['fn']);
        $this->assertSame(hash('sha256', 'putriayu'), $user['ln']);
        $this->assertSame(hash('sha256', 'bekasi'), $user['ct']);
        $this->assertSame(hash('sha256', 'jawabarat'), $user['st']);
        $this->assertSame(hash('sha256', '17510'), $user['zp']);
        $this->assertSame(hash('sha256', 'id'), $user['country']);
        $this->assertSame(hash('sha256', (string) $lead->getKey()), $user['external_id']);
        $this->assertSame(['fb.1.1700000000.123', 'fb.1.1700000000.AbC'], [$user['fbp'], $user['fbc']]);

        $custom = $event['custom_data'];
        $this->assertSame(['IDR', 827000, 3], [$custom['currency'], $custom['value'], $custom['num_items']], '349.000 x 2 + 129.000, no shipping');
        $this->assertSame([(string) $raket->getKey(), (string) $tas->getKey()], $custom['content_ids']);
        $this->assertSame('Raket Capra Pro, Tas Selempang Sport', $custom['content_name']);
        $this->assertSame(['ARAkLk-clid', '120210000000001'], [$custom['ctwa_clid'], $custom['ad_id']]);

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->body())->implode("\n");
        foreach (['6281234567890', '0812-3456-7890', 'rina@contoh.id', 'Rina', 'Bekasi', 'Mawar'] as $raw) {
            $this->assertStringNotContainsStringIgnoringCase($raw, $sent);
        }
        $this->assertStringContainsString('"Purchase"', Http::recorded(fn (Request $r) => str_contains($r->url(), 'tiktok'))->first()[0]->body());
        Log::shouldNotHaveReceived('warning');

        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->getKey(), 'body' => 'Event Purchase dikirim ke Meta']);
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->getKey(), 'body' => 'Event Purchase dikirim ke TikTok']);
        $this->assertTrue($this->in(fn () => TrackingSetting::current()->last_results['meta']['ok']));
    }

    public function test_without_an_order_the_value_is_the_lead_products_price_and_a_new_order_may_purchase_again(): void
    {
        $this->tracking();
        $raket = $this->in(fn () => Property::create(['name' => 'Raket Capra Pro', 'price_from' => 349000]));
        $lead = $this->lead(['property_id' => $raket->getKey()]);

        $this->move($lead, 'Order masuk');
        $this->assertSame([349000, [(string) $raket->getKey()], 1], [$this->metaEvents()[0]['custom_data']['value'], $this->metaEvents()[0]['custom_data']['content_ids'], $this->metaEvents()[0]['custom_data']['num_items']]);

        $order = fn () => $this->in(fn () => $lead->activities()->create(['type' => 'order_recorded', 'body' => 'Pesanan']));
        $first = $order();
        $this->move($lead, 'Dikemas & dikirim');
        $this->move($lead, 'Tanya produk');
        $this->move($lead, 'Dikemas & dikirim');
        $second = $order();
        $this->move($lead, 'Tanya produk');
        $this->move($lead, 'Dikemas & dikirim');

        $this->assertSame([
            'lead-'.$lead->getKey().'-InitiateCheckout',
            'lead-'.$lead->getKey().'-Purchase-'.$first->getKey(),
            'lead-'.$lead->getKey().'-Purchase-'.$second->getKey(),
        ], array_column($this->metaEvents(), 'event_id'));
    }

    public function test_a_failing_platform_never_stops_the_stage_move_and_logs_no_secret(): void
    {
        $this->tracking();
        $lead = $this->lead();
        $this->status = 400;
        Log::spy();

        $this->move($lead, 'Order masuk');

        $this->assertSame($this->stageId('Order masuk'), $lead->fresh()->stage_id);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'meta') && $context['status'] === 400
            && ! str_contains(json_encode($context), 'meta-secret') && ! str_contains(json_encode($context), '628'));
        $last = $this->in(fn () => TrackingSetting::current()->last_results);
        $this->assertSame([false, 400, 'InitiateCheckout'], [$last['meta']['ok'], $last['meta']['status'], $last['meta']['event']]);
    }

    // ---- Business messaging ------------------------------------------------------------------------------------

    public function test_business_messaging_goes_only_for_a_cloud_api_chat_with_a_ctwa_clid(): void
    {
        $this->tracking(['messaging' => true]);
        $official = $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Official, 'name' => 'API', 'phone' => '+6281100000001', 'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Off,
            'credentials' => ['phone_number_id' => '555', 'access_token' => 'wa-token', 'app_secret' => 's', 'verify_token' => 'v', 'waba_id' => '102030405060'],
        ]));
        $gateway = $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002', 'status' => WaChannelStatus::Connected, 'position' => 2, 'ai_mode' => AiMode::Off,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
        $chat = fn (WaChannel $channel, string $from, ?array $ad) => $this->in(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize($from), 'text', 'Halo', null, CarbonImmutable::now(), 'Rina', $ad,
        )));
        $leadOf = fn (string $phone) => $this->in(fn () => Lead::query()->where('phone', PhoneNumber::normalize($phone))->firstOrFail());
        $ad = ['source_id' => '120210000000001', 'headline' => 'Raket', 'ctwa_clid' => 'ARAkLk-clid'];

        $chat($official, '081211110001', $ad);
        $chat($gateway, '081211110002', $ad);
        $chat($official, '081211110003', null);

        foreach (['081211110001', '081211110002', '081211110003'] as $phone) {
            $this->move($leadOf($phone), 'Dikemas & dikirim');
        }

        $messaging = Http::recorded(fn (Request $r) => str_contains($r->url(), '/777000111/events'));
        $this->assertCount(1, $messaging, 'only the Cloud API chat from an ad');
        $event = $messaging->first()[0]['data'][0];
        $this->assertSame(['Purchase', 'business_messaging', 'whatsapp'], [$event['event_name'], $event['action_source'], $event['messaging_channel']]);
        $this->assertSame(['whatsapp_business_account_id' => '102030405060', 'ctwa_clid' => 'ARAkLk-clid'], $event['user_data']);
        $this->assertSame('Bearer wa-token', $messaging->first()[0]->header('Authorization')[0]);
        $this->assertSame('777000111', $official->fresh()->credential('dataset_id'), 'the dataset id is kept');
        $this->assertCount(3, $this->metaEvents(), 'the pixel still gets all three, as system_generated');
        $this->assertSame(['system_generated'], array_values(array_unique(array_column($this->metaEvents(), 'action_source'))));

        // Becoming Hot is a qualified lead there.
        $this->in(fn () => $leadOf('081211110001')->update(['interest' => Interest::Hot]));
        $this->assertSame('QualifiedLead', Http::recorded(fn (Request $r) => str_contains($r->url(), '/777000111/events'))->last()[0]['data'][0]['event_name']);
    }

    public function test_business_messaging_stays_off_unless_switched_on(): void
    {
        $this->tracking();
        $official = $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Official, 'name' => 'API', 'phone' => '+6281100000001', 'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Off,
            'credentials' => ['phone_number_id' => '555', 'access_token' => 'wa-token', 'app_secret' => 's', 'verify_token' => 'v', 'waba_id' => '102030405060'],
        ]));
        $this->in(fn () => app(HandleInboundWhatsApp::class)->message($official, new InboundMessage(
            'in-1', '+6281211110001', 'text', 'Halo', null, CarbonImmutable::now(), 'Rina', ['source_id' => '1', 'ctwa_clid' => 'clid'],
        )));

        $this->move($this->in(fn () => Lead::query()->firstOrFail()), 'Order masuk');

        $this->assertCount(0, Http::recorded(fn (Request $r) => str_contains($r->url(), 'dataset') || str_contains($r->url(), '777000111')));
        $this->assertCount(1, $this->metaEvents());
    }

    // ---- The Pelacakan page ------------------------------------------------------------------------------------

    public function test_the_pipeline_settings_start_switched_off_with_the_shop_defaults_and_save(): void
    {
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        $defaults = PipelineConversions::defaults('retail');
        $this->assertSame([false, 'Lead', false], [$defaults['enabled'], $defaults['hot'], $defaults['messaging']]);
        $this->assertSame([$this->stageId('Order masuk') => 'InitiateCheckout', $this->stageId('Dikemas & dikirim') => 'Purchase'], $defaults['stages']);

        Livewire::test(TrackingSettings::class)
            ->assertSee('Mati.')
            ->callAction('pipeline', ['enabled' => true, 'hot' => '', 'stages' => ['s'.$this->stageId('Selesai') => 'Purchase', 's'.$this->stageId('Order masuk') => '']])
            ->assertHasNoActionErrors()
            ->assertSee('Selesai: Purchase');

        $this->assertSame(['enabled' => true, 'hot' => null, 'stages' => [$this->stageId('Dikemas & dikirim') => 'Purchase', $this->stageId('Selesai') => 'Purchase'], 'messaging' => false],
            TrackingSetting::current()->pipeline(), 'the default stays, the cleared one goes, the new one is added');
    }

    public function test_property_agencies_default_to_schedule_for_the_survey_and_purchase_for_closing(): void
    {
        $agency = $this->makeTenant('griya-uji');
        $defaults = app(CurrentTenant::class)->run($agency, fn () => PipelineConversions::defaults('properti'));

        $this->assertSame([$this->stage($agency, 'Jadwal survei')->getKey() => 'Schedule', $this->stage($agency, 'Closing')->getKey() => 'Purchase'], $defaults['stages']);
        $this->assertNull($defaults['hot']);
    }

    public function test_the_test_event_goes_only_where_a_test_code_is_set_and_shows_the_last_result(): void
    {
        $this->tracking([], ['meta_test_event_code' => 'TEST123']);
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(TrackingSettings::class)->callAction(TestAction::make('test'), ['event' => 'Purchase'])->assertNotified('Event uji Purchase dikirim ke Meta');

        $meta = Http::recorded(fn (Request $r) => str_contains($r->url(), '/1234567890/events'));
        $this->assertCount(1, $meta);
        $this->assertSame('TEST123', $meta->first()[0]['test_event_code']);
        $this->assertSame('system_generated', $meta->first()[0]['data'][0]['action_source']);
        $this->assertCount(0, Http::recorded(fn (Request $r) => str_contains($r->url(), 'tiktok')), 'TikTok has no test code: nothing sent');

        Livewire::test(TrackingSettings::class)->assertSee('Kiriman terakhir dari server')->assertSee('Berhasil');
    }
}
