<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\MoveLeadToStage;
use App\Actions\ProvisionTenant;
use App\Ai\PropertyTools;
use App\Automation\PropertyStages;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\CtwaReadinessPage;
use App\Filament\Pages\TrackingSettings;
use App\Filament\Resources\WaChannels\Pages\EditWaChannel;
use App\Filament\Resources\WaChannels\WaChannelResource;
use App\Models\ConversionEvent;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaLabelEvent;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\Tracking\PipelineConversions;
use App\Tracking\PropertyAdDefaults;
use App\WhatsApp\BusinessLabels;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A property agency's Click-to-WhatsApp signals, the same flow as a shop: pipeline events to Meta (Lead, QualifiedLead,
 * Schedule, InitiateCheckout, Purchase, and Contact on the first reply) and the three standard WhatsApp labels, also
 * from automatic stage moves, each exactly once. Every client is made up; numbers start 0899000.
 */
class PropertyAdSignalsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private WaChannel $gw;

    private int $n = 0;

    /** @var list<array> the model's answers, in order */
    private array $ai = [];

    /** The account's labels, as the gateway reports them. */
    private array $accountLabels = [['id' => '1', 'name' => 'Prospek', 'color' => 1], ['id' => '2', 'name' => 'Order baru', 'color' => 2], ['id' => '3', 'name' => 'Lunas', 'color' => 3]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('griya-uji');
        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => fn () => Http::response(array_shift($this->ai) ?? ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Baik Kak 😊']]]),
            'gw.example.com/sessions/*/chat-labels' => fn () => Http::response(['label' => ['id' => '1', 'name' => 'x'], 'already' => false]),
            'gw.example.com/sessions/*/labels/resync' => fn () => Http::response(['labels' => $this->accountLabels, 'resynced' => true]),
            'gw.example.com/sessions/*/labels' => fn () => Http::response(['labels' => $this->accountLabels]),
            'gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n]),
            'graph.facebook.com/*' => Http::response(['events_received' => 1]),
            '*' => Http::response(['ok' => true]),
        ]);
        $this->gw = $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'Asisten', 'phone' => '+6289900000001', 'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Auto,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function in(callable $fn, ?Tenant $tenant = null): mixed
    {
        return app(CurrentTenant::class)->run($tenant ?? $this->tenant, $fn);
    }

    /** Meta connected; the pipeline as the agency starts with it unless given. */
    private function pixel(?array $pipeline = null): void
    {
        $this->in(fn () => TrackingSetting::create(['meta_pixel_id' => '1234567890', 'credentials' => ['meta_capi_token' => 'meta-secret'], 'pipeline_events' => $pipeline]));
    }

    private bool $first = true;

    private function says(string $text, bool $fromAd = true): void
    {
        $ad = $fromAd && $this->first;
        $this->first = false;
        $this->in(fn () => app(HandleInboundWhatsApp::class)->message($this->gw, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6289900001234'), 'text', $text, null, CarbonImmutable::now(), 'Klien Uji',
            $ad ? ['source_id' => '120210000000001', 'headline' => 'Rumah Uji', 'ctwa_clid' => 'clid-uji'] : null,
        )));
    }

    private function lead(): Lead
    {
        return $this->in(fn () => Lead::query()->with('stage')->orderBy('id')->firstOrFail());
    }

    private function step(string $key): Stage
    {
        return $this->in(fn () => (new PropertyStages)->stage($key));
    }

    /** @return list<string> the events Meta received, in order */
    private function metaEvents(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/1234567890/events'))->map(fn ($p) => $p[0]['data'][0]['event_name'])->values()->all();
    }

    private function metaData(string $event): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/1234567890/events'))->map(fn ($p) => $p[0]['data'][0])->firstWhere('event_name', $event);
    }

    /** @return list<string> the labels asked of the gateway, in order */
    private function labelled(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/chat-labels'))->map(fn ($p) => $p[0]['label'])->values()->all();
    }

    private function needs(array $input): array
    {
        return ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'tu_'.++$this->n, 'name' => PropertyTools::NEEDS, 'input' => $input]]];
    }

    // ---- Defaults ---------------------------------------------------------------------------------------------

    public function test_a_new_property_agency_starts_with_jitus_flow_and_an_existing_one_keeps_its_own(): void
    {
        $this->pixel();
        $pipeline = $this->in(fn () => TrackingSetting::current()->pipeline());

        $this->assertTrue($pipeline['enabled']);
        $this->assertSame([
            $this->step(PropertyStages::NEW)->id => 'Lead', $this->step(PropertyStages::QUALIFIED)->id => 'QualifiedLead', $this->step(PropertyStages::SURVEY)->id => 'Schedule',
            $this->step(PropertyStages::BOOKING)->id => 'InitiateCheckout', $this->step(PropertyStages::CLOSING)->id => 'Purchase',
        ], $pipeline['stages']);
        $this->assertSame(['QualifiedLead', false, true], [$pipeline['hot'], $pipeline['messaging'], $pipeline['contact']]);
        $this->assertSame('Lead', $this->in(fn () => TrackingSetting::current()->eventFor('meta')));

        // An agency made before (no flag) starts with nothing on, as before.
        $this->tenant->forceFill(['pipeline_settings' => null])->save();
        $this->assertFalse($this->in(fn () => TrackingSetting::current()->pipeline())['enabled']);

        // And one that saved its own mapping keeps it.
        $this->tenant->forceFill(['pipeline_settings' => ['ad_defaults' => 'jitu']])->save();
        $this->in(fn () => TrackingSetting::current()->forceFill(['pipeline_events' => ['enabled' => true, 'hot' => null, 'stages' => [], 'messaging' => false]])->save());
        $this->assertSame([], $this->in(fn () => TrackingSetting::current()->pipeline())['stages']);
    }

    // ---- Automatic moves send each event and label once --------------------------------------------------------

    public function test_an_automatic_move_sends_exactly_one_meta_event_and_exactly_one_label(): void
    {
        $this->pixel();
        $this->in(fn () => $this->gw->forceFill(['label_settings' => PropertyAdDefaults::labels($this->gw)])->save());

        $this->says('Halo kak, mau info rumahnya');
        $this->ai = [$this->needs(['tujuan' => 'hunian']), ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Siap Kak 😊 Domisili di mana?']]]];
        $this->says('Untuk tinggal kak');
        $this->ai = [$this->needs(['domisili' => 'Surabaya']), ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Siap Kak, dari Surabaya ya 😊']]]];
        $this->says('Saya di Surabaya');

        $this->assertSame('Terkualifikasi', $this->lead()->stage->name);
        $this->assertSame(['Lead', 'Contact', 'QualifiedLead'], $this->metaEvents(), 'Lead when the lead is made, Contact on the first reply, QualifiedLead from the automatic move');
        $this->assertSame(['Prospek'], $this->labelled());

        // Moving back and forward again sends nothing new.
        $admin = $this->adminOf($this->tenant);
        $this->in(fn () => app(MoveLeadToStage::class)($this->lead(), $this->step(PropertyStages::CONTACTED), $admin));
        $this->in(fn () => app(MoveLeadToStage::class)($this->lead(), $this->step(PropertyStages::QUALIFIED), $admin));
        $this->says('Oke kak');

        $this->assertSame(1, $this->in(fn () => ConversionEvent::query()->where('event_name', 'QualifiedLead')->count()));
        $this->assertSame(1, $this->in(fn () => ConversionEvent::query()->where('event_name', 'Contact')->count()));
        $this->assertSame(['Prospek'], $this->labelled());
        $this->assertSame(1, $this->in(fn () => WaLabelEvent::query()->count()));
    }

    public function test_the_contact_event_waits_for_a_reply_after_our_message_and_can_be_off(): void
    {
        $this->pixel(['enabled' => true, 'hot' => null, 'stages' => [], 'messaging' => false, 'contact' => false]);
        $this->in(fn () => $this->gw->forceFill(['ai_mode' => AiMode::Off])->save());
        $this->says('Halo');
        $this->says('Halo kak?');
        $this->assertSame([], $this->metaEvents());

        $this->in(fn () => TrackingSetting::current()->forceFill(['pipeline_events' => ['enabled' => true, 'contact' => true]])->save());
        $this->says('Kak?');
        $this->assertSame([], $this->metaEvents(), 'nobody has answered the client yet');
    }

    public function test_a_property_purchase_carries_the_deal_value_and_the_type(): void
    {
        $this->pixel();
        $tulip = $this->in(fn () => Property::create(['name' => 'Tipe Tulip', 'price_from' => 600_000_000]));
        $this->says('Halo');
        $this->in(fn () => $this->lead()->forceFill(['property_id' => $tulip->id])->save());

        $this->in(fn () => app(MoveLeadToStage::class)($this->lead(), $this->step(PropertyStages::CLOSING), $this->adminOf($this->tenant), ['unit' => 'Blok A-7', 'deal_value' => 642_850_000]));

        $custom = $this->metaData('Purchase')['custom_data'];
        $this->assertSame(642850000, (int) $custom['value']);
        $this->assertSame('IDR', $custom['currency']);
        $this->assertSame('Tipe Tulip (Blok A-7)', $custom['content_name']);
        $this->assertSame('clid-uji', $custom['ctwa_clid']);
        $this->assertSame('120210000000001', (string) $custom['ad_id']);
    }

    // ---- "Samakan dengan bawaan Jitu" ---------------------------------------------------------------------------

    public function test_an_agency_with_its_own_mapping_sees_the_differences_before_matching_jitu(): void
    {
        $this->tenant->forceFill(['pipeline_settings' => null])->save();
        $this->pixel(['enabled' => true, 'hot' => null, 'messaging' => true, 'stages' => [$this->step(PropertyStages::BOOKING)->id => 'AddToCart']]);
        $this->in(fn () => TrackingSetting::current()->forceFill(['meta_event' => 'Schedule'])->save());
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        $diff = $this->in(fn () => PropertyAdDefaults::differences(TrackingSetting::current()));
        $this->assertContains(['Tahap Booking', 'AddToCart', 'InitiateCheckout'], $diff);
        $this->assertContains(['Event formulir dan landing (Meta)', 'Schedule', 'Lead'], $diff);
        $this->assertContains(['Conversions API for Business Messaging', 'hidup', 'mati'], $diff);

        Livewire::test(TrackingSettings::class)
            ->callAction('jituDefaults')->assertNotified('Pelacakan disamakan dengan bawaan Jitu');

        $setting = TrackingSetting::current();
        $this->assertSame('Lead', $setting->meta_event);
        $this->assertSame('1234567890', $setting->meta_pixel_id);
        $this->assertSame('meta-secret', $setting->credential('meta_capi_token'));
        $this->assertSame([], PropertyAdDefaults::differences($setting));
    }

    public function test_business_messaging_on_for_a_gateway_only_agency_is_warned_about(): void
    {
        $this->pixel();
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(TrackingSettings::class)->callAction('pipeline', ['enabled' => true, 'messaging' => true])
            ->assertNotified('Business Messaging tidak berlaku untuk nomor Anda');
        Livewire::test(CtwaReadinessPage::class)->assertSee('Conversions API for Business Messaging menyala tanpa nomor Cloud API');
    }

    // ---- Labels -------------------------------------------------------------------------------------------------

    public function test_the_property_label_defaults_and_the_one_click_setup(): void
    {
        $rules = $this->in(fn () => BusinessLabels::defaults('properti'))['rules'];
        $this->assertSame([true, [$this->step(PropertyStages::QUALIFIED)->id, $this->step(PropertyStages::SURVEY)->id]], [$rules[0]['hot'], $rules[0]['stages']]);
        $this->assertSame([[$this->step(PropertyStages::BOOKING)->id], [$this->step(PropertyStages::CLOSING)->id]], [$rules[1]['stages'], $rules[2]['stages']]);

        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(EditWaChannel::class, ['record' => $this->gw->getKey()])
            ->assertFormSet(['label_settings.enabled' => false])
            ->callAction([['name' => 'jituLabels', 'context' => ['schemaComponent' => 'form.wa_labels.wa_label_actions']]])
            ->assertFormSet(['label_settings.enabled' => true, 'label_settings.ads_only' => true]);

        $stored = BusinessLabels::settings($this->gw->fresh());
        $this->assertSame(['Prospek', 'Order baru', 'Lunas'], array_column($stored['rules'], 'name'));
        $this->assertTrue($stored['enabled']);
    }

    public function test_loading_labels_explains_a_stale_list_missing_labels_and_labels_that_are_not_signals(): void
    {
        $this->assertTrue(BusinessLabels::staleList(['Grup', 'Favorit', 'Belum dibaca']));
        $this->assertFalse(BusinessLabels::staleList(['Grup', 'Prospek']));
        $this->assertTrue(BusinessLabels::isSignal('Lead'));
        $this->assertFalse(BusinessLabels::isSignal('Pesanan selesai'));

        $this->accountLabels = [['id' => 'g', 'name' => 'Grup'], ['id' => 'f', 'name' => 'Favorit'], ['id' => 'u', 'name' => 'Belum dibaca']];
        $this->in(fn () => $this->gw->forceFill(['label_settings' => PropertyAdDefaults::labels($this->gw)])->save());
        $this->in(fn () => WaChannelResource::loadLabels($this->gw->fresh()));

        $this->assertSame(['Grup', 'Favorit', 'Belum dibaca'], BusinessLabels::settings($this->gw->fresh())['known']);
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(CtwaReadinessPage::class)
            ->assertSee('Gateway hanya melihat Grup, Favorit, Belum dibaca')
            ->assertSee('Buka satu chat UJI (bukan pelanggan), centang Prospek, Order baru, dan Lunas');
    }

    // ---- Readiness page -----------------------------------------------------------------------------------------

    public function test_the_readiness_page_lists_what_is_missing_and_the_30_day_summary(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(CtwaReadinessPage::class)
            ->assertSee('Isi Pixel ID dan token Conversions API')
            ->assertSee('Label otomatis mati')
            ->assertSee('Berbagi data WhatsApp Business ke Meta')
            ->assertSee('Belum ada event dalam 30 hari.');

        $this->pixel();
        $this->in(fn () => $this->gw->forceFill(['label_settings' => PropertyAdDefaults::labels($this->gw)])->save());
        $this->in(fn () => WaChannelResource::loadLabels($this->gw->fresh()));
        $this->says('Halo');

        Livewire::test(CtwaReadinessPage::class)
            ->assertSee('Pixel 1234567890 dengan token tersimpan.')
            ->assertSee('Prospek, Order baru, dan Lunas ada di akun.')
            ->assertSee('1 dari 1 lead WhatsApp 30 hari terakhir membawa data iklan')
            ->assertSeeText('Lead: 1');
    }

    // ---- Retail and other agencies ------------------------------------------------------------------------------

    public function test_shops_keep_their_defaults_pages_and_values(): void
    {
        $shop = app(ProvisionTenant::class)('Toko Uji', 'toko-uji', User::factory()->create(), 'retail');
        $defaults = $this->in(fn () => PipelineConversions::defaults('retail'), $shop);
        $this->assertSame([false, 'Lead', false, false], [$defaults['enabled'], $defaults['hot'], $defaults['messaging'], $defaults['contact']]);
        $this->assertNull($this->in(fn () => TrackingSetting::starting(), $shop));
        $this->assertNull(data_get($shop->fresh()->pipeline_settings, 'ad_defaults'));

        $lead = $this->in(fn () => Lead::create(['name' => 'Pembeli Uji', 'phone' => '089900003333', 'deal_value' => 500000, 'stage_id' => Stage::query()->value('id'),
            'order_items' => [['product_id' => 1, 'name' => 'Kaos', 'price' => 89000, 'quantity' => 2]]]), $shop);
        $this->assertSame(178000, $this->in(fn () => PipelineConversions::customData($lead), $shop)['value'], 'a shop still counts its order, not the deal value');

        $this->actingInTenant($this->adminOf($shop), $shop);
        $this->assertFalse(CtwaReadinessPage::canAccess());
        Livewire::test(TrackingSettings::class)->assertActionHidden('jituDefaults');
    }
}
