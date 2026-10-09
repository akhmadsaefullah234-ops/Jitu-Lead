<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\MoveLeadToStage;
use App\Actions\RunFollowUps;
use App\Actions\SendWhatsAppMessage;
use App\Billing\PlanLimits;
use App\Billing\Subscriptions;
use App\Enums\AiMode;
use App\Enums\Role;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Reports;
use App\Filament\Pages\Subscription as SubscriptionPage;
use App\Filament\Pages\TrackingSettings;
use App\Filament\Resources\FollowUpRules\Pages\ManageFollowUpRules;
use App\Filament\Resources\LandingPages\Pages\CreateLandingPage;
use App\Filament\Resources\Team\Pages\ManageTeam;
use App\Filament\Resources\WaChannels\Pages\CreateWaChannel;
use App\Filament\Widgets\PlanAlerts;
use App\Mail\SubscriptionEnding;
use App\Models\AiDraft;
use App\Models\AiKnowledgeItem;
use App\Models\FollowUpRule;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\Membership;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use App\WhatsApp\WhatsAppException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SubscriptionPlanTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        config(['services.anthropic.key' => 'test-key']);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake(['api.anthropic.com/*' => fn () => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Mulai 450 juta, Kak.']]])]);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function inTenant(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    /** Puts the agency on a paid plan (or a status) in place, so the cached subscription stays current. */
    private function plan(string $plan, string $status = Subscription::ACTIVE, array $extra = []): void
    {
        $this->tenant->currentSubscription()->update($extra + [
            'plan' => $plan, 'status' => $status, 'billing_cycle' => 'monthly',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);
    }

    private function admin(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
    }

    private function channel(string $name = 'GW', AiMode $mode = AiMode::Off): WaChannel
    {
        return $this->inTenant(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => $name, 'status' => WaChannelStatus::Connected, 'position' => ++$this->n, 'ai_mode' => $mode,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function aiCalls(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.anthropic.com'))->count();
    }

    // --- plans and trial ---

    public function test_new_agency_starts_a_14_day_trial_with_team_plan_limits(): void
    {
        $sub = $this->tenant->currentSubscription();

        $this->assertSame(Subscription::TRIAL, $sub->status);
        $this->assertEqualsWithDelta(14, now()->diffInDays($sub->trial_ends_at), 1);
        $this->assertSame('tim', $sub->effectivePlanKey());
        $limits = PlanLimits::for($this->tenant);
        $this->assertSame(5, $limits->limit('users'));
        $this->assertSame(1500, $limits->limit('ai'));
        $this->assertTrue($limits->feature('pixels'));
    }

    public function test_plan_definitions_come_from_one_config_and_match_the_agreed_table(): void
    {
        $plans = config('plans.plans');

        $this->assertSame([79000, 790000], [$plans['mandiri']['price']['monthly'], $plans['mandiri']['price']['yearly']]);
        $this->assertSame([199000, 1990000], [$plans['tim']['price']['monthly'], $plans['tim']['price']['yearly']]);
        $this->assertSame([449000, 4490000], [$plans['agensi']['price']['monthly'], $plans['agensi']['price']['yearly']]);
        $this->assertSame(['users' => 1, 'leads' => 300, 'wa' => 1, 'ai' => 300, 'followups' => 2, 'landing_pages' => 1], $plans['mandiri']['limits']);
        $this->assertSame(['users' => 15, 'leads' => 10000, 'wa' => 4, 'ai' => 5000, 'followups' => null, 'landing_pages' => null], $plans['agensi']['limits']);
        $this->assertTrue($plans['tim']['popular']);
        $this->assertSame([30000, 25000, 30000], [config('plans.addons.ai.price'), config('plans.addons.user.price'), config('plans.addons.wa.price')]);

        $this->get('/harga')->assertOk()->assertSee('Agen Mandiri')->assertSee('Rp 199.000')->assertSee('Rp 4.490.000')->assertSee('Paling populer');
    }

    public function test_existing_agencies_get_a_trial_from_the_migration_and_lazily_otherwise(): void
    {
        $this->tenant->subscription()->delete();
        $this->tenant->unsetRelation('subscription');

        $sub = $this->tenant->currentSubscription();

        $this->assertSame(Subscription::TRIAL, $sub->status);
        $this->assertSame(1, Subscription::count());
    }

    // --- limits ---

    public function test_whatsapp_number_limit_blocks_adding_and_offers_an_upgrade(): void
    {
        $this->plan('mandiri');
        $this->channel('Satu');
        $this->admin();

        Livewire::test(CreateWaChannel::class)
            ->fillForm(['type' => 'gateway', 'name' => 'Dua', 'cred' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's']])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Batas paket tercapai');

        $this->assertSame(1, $this->inTenant(fn () => WaChannel::count()));
    }

    public function test_user_limit_blocks_adding_team_members_until_an_addon_is_bought(): void
    {
        $this->plan('mandiri');
        $this->admin();

        Livewire::test(ManageTeam::class)
            ->callAction('add', ['name' => 'Rani', 'email' => 'rani@example.com', 'password' => 'rahasia-panjang-1', 'role' => Role::Agent->value])
            ->assertNotified('Batas paket tercapai');
        $this->assertSame(1, Membership::where('tenant_id', $this->tenant->getKey())->count());

        Artisan::call('plan:addon', ['tenant' => $this->tenant->slug, 'kind' => 'user', '--qty' => 1]);
        $this->tenant->unsetRelation('subscription');

        Livewire::test(ManageTeam::class)
            ->callAction('add', ['name' => 'Rani', 'email' => 'rani@example.com', 'password' => 'rahasia-panjang-1', 'role' => Role::Agent->value])
            ->assertHasNoActionErrors();
        $this->assertSame(2, Membership::where('tenant_id', $this->tenant->getKey())->count());
    }

    public function test_landing_page_and_follow_up_limits(): void
    {
        $this->plan('mandiri');
        $this->inTenant(fn () => LandingPage::create(['title' => 'A', 'slug' => 'a', 'status' => 'draft', 'color' => '#dc2626', 'blocks' => []]));
        $this->admin();

        Livewire::test(CreateLandingPage::class)->fillForm(['title' => 'B', 'slug' => 'b', 'color' => '#dc2626'])->call('create')->assertNotified('Batas paket tercapai');
        $this->assertSame(1, $this->inTenant(fn () => LandingPage::count()));

        // The starter follow-ups already fill the 2 allowed on this plan.
        $this->assertGreaterThanOrEqual(2, $this->inTenant(fn () => FollowUpRule::count()));
        $before = $this->inTenant(fn () => FollowUpRule::count());
        Livewire::test(ManageFollowUpRules::class)->callAction('create', ['name' => 'Baru', 'delay_days' => 2, 'send_time' => '09:00', 'body' => 'Halo {nama}', 'active' => true])->assertNotified('Batas paket tercapai');
        $this->assertSame($before, $this->inTenant(fn () => FollowUpRule::count()));
    }

    public function test_downgrade_keeps_all_data_and_only_blocks_new_additions(): void
    {
        $this->channel('A');
        $this->channel('B');
        $this->plan('mandiri');

        $this->assertSame(2, $this->inTenant(fn () => WaChannel::count()));
        $this->assertTrue(PlanLimits::for($this->tenant)->atLimit('wa'));
        $this->assertNotNull(PlanLimits::for($this->tenant)->denyAdding('wa'));
    }

    public function test_pixels_and_full_reports_are_locked_on_the_single_agent_plan(): void
    {
        $this->inTenant(fn () => TrackingSetting::create(['meta_pixel_id' => '1234567890']));
        $token = $this->inTenant(fn () => $this->tenant->regenerateCaptureToken());
        $this->inTenant(fn () => LandingPage::create(['title' => 'A', 'slug' => 'a', 'status' => 'published', 'color' => '#dc2626', 'blocks' => [['type' => 'form', 'data' => ['heading' => 'Daftar', 'button' => 'Kirim']]]]));
        $this->get('/p/griya-prima/a')->assertOk()->assertSee('fbevents.js', false);

        $this->plan('mandiri');
        $this->get('/p/griya-prima/a')->assertOk()->assertDontSee('fbevents.js', false);

        $this->admin();
        Livewire::test(TrackingSettings::class)->assertSee('tidak termasuk paket Agen Mandiri')->assertDontSee('Atur pelacakan');
        Livewire::test(Reports::class)->assertSee('tidak termasuk paket Agen Mandiri');

        $this->plan('tim');
        Livewire::test(Reports::class)->assertDontSee('tidak termasuk paket');
        $this->assertNotEmpty($token);
    }

    // --- leads are never dropped ---

    public function test_leads_over_the_active_limit_are_still_accepted_with_only_a_warning(): void
    {
        $this->plan('mandiri');
        config(['plans.plans.mandiri.limits.leads' => 1]);
        $token = $this->inTenant(fn () => $this->tenant->regenerateCaptureToken());

        $this->postJson("/capture/{$token}", ['name' => 'Satu', 'phone' => '081200000001'])->assertOk();
        $this->postJson("/capture/{$token}", ['name' => 'Dua', 'phone' => '081200000002'])->assertOk();

        $this->assertSame(2, $this->inTenant(fn () => Lead::count()));
        $this->assertTrue(PlanLimits::for($this->tenant)->atLimit('leads'));

        $this->admin();
        $this->assertTrue(PlanAlerts::canView());
        $this->assertStringContainsString('Lead baru tetap masuk', collect(PlanAlerts::alerts())->pluck('text')->implode(' '));
    }

    public function test_only_open_stage_leads_count_as_active(): void
    {
        $won = $this->inTenant(fn () => Stage::where('type', 'won')->firstOrFail());
        $this->inTenant(function () use ($won) {
            Lead::create(['name' => 'Menang', 'phone' => '081200000009', 'stage_id' => $won->getKey()]);
            Lead::create(['name' => 'Baru', 'phone' => '081200000008', 'stage_id' => Stage::where('type', 'open')->first()->getKey()]);
        });

        $this->assertSame(1, PlanLimits::for($this->tenant)->usage('leads'));
    }

    // --- AI quota ---

    private function aiMessage(): WaMessage
    {
        $channel = $this->channel('AI', AiMode::Auto);
        $this->inTenant(fn () => AiKnowledgeItem::create(['title' => 'Harga', 'content' => 'Mulai 450 juta']));

        return $this->inTenant(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6281234567890'), 'text', 'Berapa harga?', null, CarbonImmutable::now(), 'Budi',
        )));
    }

    public function test_exhausted_ai_quota_hands_over_without_calling_anthropic(): void
    {
        $this->plan('mandiri');
        config(['plans.plans.mandiri.limits.ai' => 1]);
        $first = $this->aiMessage();

        $this->assertSame(1, $this->aiCalls(), 'the first answer uses the only reply');
        $this->assertSame(1, PlanLimits::for($this->tenant)->usage('ai'));

        $channel = WaChannel::withoutGlobalScopes()->first();
        $second = $this->inTenant(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6281234567890'), 'text', 'Masih ada unit?', null, CarbonImmutable::now(), 'Budi',
        )));

        $this->assertSame(1, $this->aiCalls(), 'no second call to Anthropic');
        $draft = $this->inTenant(fn () => AiDraft::where('message_id', $second->getKey())->firstOrFail());
        $this->assertSame(AiDraft::HANDOFF, $draft->status);
        $this->assertSame('Kuota AI bulan ini habis', $draft->reason);
        $this->assertFalse((bool) $draft->api_call);
        $this->assertNotNull($first);
    }

    public function test_ai_quota_counts_only_the_current_period_and_addons_extend_it(): void
    {
        $this->plan('mandiri', extra: ['current_period_start' => now()->subDays(40), 'current_period_end' => now()->subDays(10)]);
        $this->plan('mandiri');
        $message = $this->aiMessage();
        $this->inTenant(fn () => AiDraft::query()->update(['created_at' => now()->subDays(60)]));

        $this->assertSame(0, PlanLimits::for($this->tenant)->usage('ai'), 'old replies do not count toward this period');

        $this->tenant->currentSubscription()->update(['addon_ai' => 2]);
        $this->assertSame(300 + 2000, PlanLimits::for($this->tenant)->limit('ai'));
        $this->assertNotNull($message);
    }

    // --- read only ---

    public function test_read_only_can_view_but_not_change_and_ai_and_follow_ups_stop(): void
    {
        $lead = $this->inTenant(fn () => Lead::create(['name' => 'Dewi', 'phone' => '081200000007', 'stage_id' => Stage::where('type', 'open')->first()->getKey()]));
        $admin = $this->adminOf($this->tenant);
        $this->plan('tim', Subscription::READ_ONLY);
        $this->admin();

        $this->assertTrue($admin->can('viewAny', Lead::class));
        $this->assertTrue($admin->can('view', $lead));
        $this->assertFalse($admin->can('create', Lead::class));
        $this->assertFalse($admin->can('update', $lead));
        $this->assertFalse($admin->can('create', WaChannel::class));
        $this->assertTrue($admin->can('viewAny', WaChannel::class));

        $stage = $this->inTenant(fn () => Stage::where('type', 'won')->firstOrFail());
        $this->expectException(ValidationException::class);
        $this->inTenant(fn () => app(MoveLeadToStage::class)($lead, $stage, $admin));
    }

    public function test_read_only_blocks_sending_ai_and_follow_ups_but_still_receives_leads(): void
    {
        $this->plan('tim', Subscription::READ_ONLY);
        $channel = $this->channel('GW', AiMode::Auto);
        $this->inTenant(fn () => AiKnowledgeItem::create(['title' => 'Harga', 'content' => 'Mulai 450 juta']));
        $token = $this->inTenant(fn () => $this->tenant->regenerateCaptureToken());

        // a form lead and a WhatsApp lead still arrive
        $this->postJson("/capture/{$token}", ['name' => 'Form', 'phone' => '081200000011'])->assertOk();
        $message = $this->inTenant(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-1', PhoneNumber::normalize('6281234567890'), 'text', 'Berapa harga?', null, CarbonImmutable::now(), 'Budi',
        )));
        $this->assertSame(2, $this->inTenant(fn () => Lead::count()));

        // but the AI stays silent: no model call, no reply
        $this->assertSame(0, $this->aiCalls());
        $this->assertNull($this->inTenant(fn () => AiDraft::where('message_id', $message->getKey())->first()));

        // manual sending is refused
        $conversation = $message->conversation;
        $this->expectException(WhatsAppException::class);
        $this->inTenant(fn () => app(SendWhatsAppMessage::class)($conversation, $this->adminOf($this->tenant), 'Halo'));
    }

    public function test_read_only_stops_automatic_follow_ups(): void
    {
        $this->plan('tim', Subscription::READ_ONLY);
        Http::fake(['gw.example.com/*' => Http::response(['id' => 'x'])]);

        $result = app(RunFollowUps::class)($this->tenant);

        $this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 0], $result);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'gw.example.com'));
    }

    // --- daily check and reminders ---

    public function test_daily_check_moves_trial_to_past_due_then_read_only_and_reminds_once(): void
    {
        Mail::fake();
        $sub = $this->tenant->currentSubscription();
        $sub->update(['trial_ends_at' => now()->addDays(2)]);

        $r = app(Subscriptions::class)->dailyCheck();
        $this->assertSame(1, $r['reminded']);
        Mail::assertSent(SubscriptionEnding::class, 1);

        $this->assertSame(0, app(Subscriptions::class)->dailyCheck()['reminded'], 'one reminder per end date');
        Mail::assertSent(SubscriptionEnding::class, 1);

        $sub->update(['trial_ends_at' => now()->subHour()]);
        $this->assertSame(1, app(Subscriptions::class)->dailyCheck()['past_due']);
        $this->assertSame(Subscription::PAST_DUE, $sub->fresh()->status);
        $this->assertEqualsWithDelta(3, now()->diffInDays($sub->fresh()->grace_ends_at), 1);

        $sub->update(['grace_ends_at' => now()->subMinute()]);
        $this->assertSame(1, app(Subscriptions::class)->dailyCheck()['read_only']);
        $this->assertSame(Subscription::READ_ONLY, $sub->fresh()->status);
    }

    public function test_past_due_still_works_during_the_grace_period(): void
    {
        $this->plan('tim', Subscription::PAST_DUE, ['grace_ends_at' => now()->addDays(2)]);

        $this->assertFalse(PlanLimits::for($this->tenant)->readOnly());
        $this->assertNull(PlanLimits::for($this->tenant)->denyAdding('wa'));
    }

    // --- artisan ---

    public function test_plan_set_activates_extends_and_approves_the_pending_request(): void
    {
        SubscriptionRequest::create(['tenant_id' => $this->tenant->getKey(), 'plan' => 'tim', 'billing_cycle' => 'monthly', 'amount' => 199000]);

        $this->artisan('plan:set', ['tenant' => $this->tenant->slug, 'plan' => 'tim'])->assertSuccessful();
        $sub = Subscription::first();
        $this->assertSame([Subscription::ACTIVE, 'tim'], [$sub->status, $sub->plan]);
        $this->assertEqualsWithDelta(30, now()->diffInDays($sub->current_period_end), 1);
        $this->assertSame(SubscriptionRequest::APPROVED, SubscriptionRequest::first()->status);

        $end = $sub->current_period_end;
        $this->artisan('plan:set', ['tenant' => $this->tenant->slug, 'plan' => 'tim', '--cycle' => 'yearly'])->assertSuccessful();
        $this->assertTrue(Subscription::first()->current_period_end->gt($end->copy()->addMonths(11)), 'a running period is extended, not restarted');

        $this->artisan('plan:set', ['tenant' => $this->tenant->slug, 'plan' => 'bogus'])->assertFailed();
        $this->artisan('plan:set', ['tenant' => 'tidak-ada', 'plan' => 'tim'])->assertFailed();
    }

    public function test_plan_set_brings_a_read_only_agency_back(): void
    {
        $this->plan('tim', Subscription::READ_ONLY);
        $this->artisan('plan:set', ['tenant' => (string) $this->tenant->getKey(), 'plan' => 'mandiri', '--months' => 3])->assertSuccessful();

        $sub = Subscription::first();
        $this->assertSame([Subscription::ACTIVE, 'mandiri'], [$sub->status, $sub->plan]);
        $this->assertNull($sub->grace_ends_at);
    }

    public function test_plan_addon_and_plan_list(): void
    {
        $this->artisan('plan:addon', ['tenant' => $this->tenant->slug, 'kind' => 'ai', '--qty' => 3])->assertSuccessful();
        $this->artisan('plan:addon', ['tenant' => $this->tenant->slug, 'kind' => 'ai', '--qty' => -10])->assertSuccessful();
        $this->assertSame(0, Subscription::first()->addon_ai, 'never below zero');
        $this->artisan('plan:addon', ['tenant' => $this->tenant->slug, 'kind' => 'xyz'])->assertFailed();

        $this->artisan('plan:list')->expectsOutputToContain($this->tenant->slug)->assertSuccessful();
        $this->artisan('plan:check')->assertSuccessful();
    }

    // --- Langganan page ---

    public function test_subscription_page_is_admin_only_and_creates_a_pending_transfer_request(): void
    {
        config(['jitu.billing_bank_info' => "Bank ABC 123456\na.n. JITU"]);
        $this->actingInTenant($this->member($this->tenant, Role::Agent), $this->tenant);
        Livewire::test(SubscriptionPage::class)->assertForbidden();

        $this->admin();
        Livewire::test(SubscriptionPage::class)
            ->assertSee('Paling populer')->assertSee('Rp 199.000')->assertSee('Pemakaian')
            ->set('cycle', 'yearly')->assertSee('Rp 1.990.000')
            ->call('choose', 'tim')
            ->assertSee('Bank ABC 123456')->assertSee('Rp 1.990.000');

        $request = SubscriptionRequest::firstOrFail();
        $this->assertSame(['tim', 'yearly', 1990000, 'pending'], [$request->plan, $request->billing_cycle, (int) $request->amount, $request->status]);

        // a second choice replaces the first
        Livewire::test(SubscriptionPage::class)->call('choose', 'agensi');
        $this->assertSame(1, SubscriptionRequest::where('status', 'pending')->count());
        $this->assertSame('agensi', SubscriptionRequest::where('status', 'pending')->first()->plan);

        Livewire::test(SubscriptionPage::class)->call('choose', 'tidak-ada');
        $this->assertSame(1, SubscriptionRequest::where('status', 'pending')->count());
    }

    public function test_read_only_agency_can_still_open_the_page_and_ask_for_a_plan(): void
    {
        $this->plan('tim', Subscription::READ_ONLY);
        $this->admin();

        Livewire::test(SubscriptionPage::class)->assertSee('Hanya-baca')->call('choose', 'tim');
        $this->assertSame(1, SubscriptionRequest::count());
    }

    // --- dashboard alerts ---

    public function test_dashboard_warns_at_80_and_100_percent(): void
    {
        $this->plan('mandiri');
        config(['plans.plans.mandiri.limits.wa' => 5]);
        foreach (range(1, 4) as $i) {
            $this->channel("N$i");
        }
        $this->admin();

        $texts = collect(PlanAlerts::alerts())->pluck('text')->implode(' | ');
        $this->assertStringContainsString('80% terpakai', $texts);

        $this->channel('N5');
        $this->assertStringContainsString('Nomor WhatsApp sudah penuh', collect(PlanAlerts::alerts())->pluck('text')->implode(' | '));

        $this->actingInTenant($this->member($this->tenant, Role::Agent), $this->tenant);
        $this->assertFalse(PlanAlerts::canView(), 'only the admin sees plan alerts');
    }
}
