<?php

namespace Tests\Feature;

use App\Actions\MoveLeadToStage;
use App\Actions\RunFollowUps;
use App\Enums\Role;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Resources\FollowUpRules\Pages\ManageFollowUpRules;
use App\Models\FollowUpLog;
use App\Models\FollowUpRule;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Support\CurrentTenant;
use App\WhatsApp\GatewayUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class FollowUpTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private bool $gatewayDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->tenant->forceFill(['timezone' => 'Asia/Jakarta'])->save();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', 'Asia/Jakarta'));
        config(['whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        $n = 0;
        Http::fake(['gw.example.com/*' => function () use (&$n) {
            return $this->gatewayDown ? Http::response(['error' => 'down'], 503) : Http::response(['id' => 'm'.++$n]);
        }]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function inTenant(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    private function gateway(): WaChannel
    {
        return $this->inTenant(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002',
            'status' => WaChannelStatus::Connected, 'position' => 1,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function rule(array $attrs = []): FollowUpRule
    {
        return $this->inTenant(fn () => FollowUpRule::create($attrs + [
            'name' => 'H+1', 'stage_id' => $this->stage($this->tenant, 'Lead baru')->getKey(),
            'delay_days' => 1, 'send_time' => '09:00', 'body' => 'Halo {nama}, saya {agen} dari {agensi}.',
        ]));
    }

    /** A lead that entered its stage at the given Jakarta wall-clock time. "Now" in these tests is 12 Oct 10:00. */
    private function lead(string $enteredAt = '2026-10-11 12:00', array $attrs = []): Lead
    {
        $lead = $this->makeLead($this->tenant, $attrs + ['name' => 'Budi Santoso']);
        $lead->forceFill(['stage_entered_at' => Carbon::parse($enteredAt, 'Asia/Jakarta')])->save();

        return $lead;
    }

    private function runFollowUps(): array
    {
        return app(RunFollowUps::class)($this->tenant);
    }

    public function test_sends_once_when_due_and_fills_variables(): void
    {
        $this->gateway();
        $this->rule();
        $lead = $this->lead();

        $this->assertSame(['sent' => 1, 'failed' => 0, 'skipped' => 0], $this->runFollowUps());
        $this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 0], $this->runFollowUps(), 'Second run must not resend');

        $conversation = $this->inTenant(fn () => WaConversation::where('lead_id', $lead->getKey())->first());
        $body = $conversation->messages()->where('is_intro', false)->first()->body;
        $this->assertStringStartsWith('Halo Budi, saya ', $body);
        $this->assertStringContainsString('Griya Prima', $body);
    }

    public function test_h_plus_one_means_the_next_day_not_24_hours_later(): void
    {
        $this->gateway();
        $this->rule();
        $this->lead('2026-10-12 08:00'); // today: H+1 is tomorrow 09:00

        $this->assertSame(0, $this->runFollowUps()['sent']);
    }

    public function test_stale_leads_are_left_alone(): void
    {
        $this->gateway();
        $this->rule();
        $this->lead('2026-10-09 12:00'); // H+1 was 10 Oct 09:00, more than a day ago

        $this->assertSame(0, $this->runFollowUps()['sent']);
    }

    public function test_waits_for_the_chosen_sending_time(): void
    {
        $this->gateway();
        $this->rule(['send_time' => '11:00']);
        $this->lead();

        $this->assertSame(0, $this->runFollowUps()['sent']);

        Carbon::setTestNow(Carbon::parse('2026-10-12 11:05:00', 'Asia/Jakarta'));
        $this->assertSame(1, $this->runFollowUps()['sent']);
    }

    public function test_stops_when_the_lead_replied_after_entering_the_stage(): void
    {
        $this->gateway();
        $this->rule();
        $lead = $this->lead();
        $this->inTenant(fn () => WaConversation::create(['lead_id' => $lead->getKey(), 'phone' => $lead->phone, 'last_inbound_at' => now()->subHours(2)]));

        $this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 1], $this->runFollowUps());
        $this->assertSame('Lead sudah membalas', FollowUpLog::withoutGlobalScopes()->first()->note);
    }

    public function test_only_rules_for_the_lead_stage_apply_and_moving_stops_them(): void
    {
        $this->gateway();
        $this->rule();
        $lead = $this->lead();
        $this->inTenant(fn () => app(MoveLeadToStage::class)($lead->fresh(), $this->stage($this->tenant, 'Dihubungi'), $this->adminOf($this->tenant)));

        $this->assertSame(0, $this->runFollowUps()['sent']);
        $this->assertNotNull($lead->fresh()->stage_entered_at);
    }

    public function test_closed_leads_and_inactive_rules_are_ignored(): void
    {
        $this->gateway();
        $this->rule(['stage_id' => null]);
        $this->rule(['name' => 'Mati', 'stage_id' => null, 'active' => false]);
        $lost = $this->lead();
        $lost->forceFill(['stage_id' => $this->stage($this->tenant, 'Gugur')->getKey()])->save();

        $this->assertSame(0, $this->runFollowUps()['sent']);
    }

    public function test_without_a_free_route_nothing_is_sent_or_logged(): void
    {
        $this->rule();
        $this->lead();

        $this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 0], $this->runFollowUps());
        $this->assertSame(0, FollowUpLog::withoutGlobalScopes()->count());

        $this->gateway();
        $this->assertSame(1, $this->runFollowUps()['sent'], 'Picked up once a number is connected');
    }

    public function test_one_message_per_lead_per_run(): void
    {
        $this->gateway();
        $this->rule(['name' => 'A', 'stage_id' => null]);
        $this->rule(['name' => 'B', 'stage_id' => null]);
        $this->lead();

        $this->assertSame(1, $this->runFollowUps()['sent']);
    }

    public function test_failed_send_is_logged_and_not_retried(): void
    {
        $this->gateway();
        $this->rule();
        $this->lead();
        $this->gatewayDown = true;

        $this->assertSame(1, $this->runFollowUps()['failed']);
        $this->assertSame(0, $this->runFollowUps()['failed']);
    }

    public function test_limit_caps_a_single_run(): void
    {
        $this->gateway();
        $this->rule();
        $this->lead();
        $this->lead('2026-10-11 12:00', ['phone' => '081200000001']);

        $this->assertSame(1, app(RunFollowUps::class)($this->tenant, 1)['sent']);
    }

    public function test_tenants_do_not_follow_up_each_others_leads(): void
    {
        $other = $this->makeTenant('rumah-lain');
        $this->gateway();
        $this->rule();
        $this->lead();

        $this->assertSame(0, app(RunFollowUps::class)($other)['sent']);
    }

    public function test_command_runs_for_every_tenant(): void
    {
        $this->gateway();
        $this->rule();
        $this->lead();

        $this->artisan('followups:run')->assertSuccessful();
        $this->assertSame(1, FollowUpLog::withoutGlobalScopes()->where('status', 'sent')->count());
    }

    public function test_new_agencies_get_starter_rules_switched_off(): void
    {
        $rules = FollowUpRule::withoutGlobalScopes()->where('tenant_id', $this->tenant->getKey())->get();

        $this->assertCount(3, $rules);
        $this->assertTrue($rules->every(fn ($r) => $r->active === false && $r->stage_id !== null));
    }

    public function test_admin_manages_rules_and_agents_cannot(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(ManageFollowUpRules::class)
            ->assertSee('Sapaan H+1')
            ->callAction('create', [
                'name' => 'Baru', 'delay_days' => 2, 'send_time' => '09:00', 'body' => 'Halo {nama}', 'active' => true,
            ])->assertHasNoActionErrors();
        $this->assertSame(1, FollowUpRule::where('name', 'Baru')->count());

        $this->actingInTenant($this->member($this->tenant, Role::Agent), $this->tenant);
        $this->assertFalse(auth()->user()->can('viewAny', FollowUpRule::class));
    }

    public function test_due_time_is_calendar_days_later_at_the_chosen_time(): void
    {
        $rule = new FollowUpRule(['delay_days' => 1, 'send_time' => '09:00']);
        $entered = Carbon::parse('2026-10-09 12:00', 'Asia/Jakarta');

        $this->assertSame('2026-10-10 09:00', $rule->dueAt($entered, 'Asia/Jakarta')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-12 21:30', (new FollowUpRule(['delay_days' => 3, 'send_time' => '21:30']))->dueAt($entered, 'Asia/Jakarta')->format('Y-m-d H:i'));
    }

    public function test_delay_must_be_a_listed_day(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(ManageFollowUpRules::class)
            ->callAction('create', ['name' => 'X', 'delay_days' => 99, 'send_time' => '09:00', 'body' => 'Hi'])
            ->assertHasActionErrors(['delay_days']);
    }
}
