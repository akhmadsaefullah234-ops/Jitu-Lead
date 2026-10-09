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
            'delay_hours' => 24, 'body' => 'Halo {nama}, saya {agen} dari {agensi}.',
        ]));
    }

    /** A lead that entered its stage the given number of hours ago. */
    private function lead(int $hoursAgo, array $attrs = []): Lead
    {
        $lead = $this->makeLead($this->tenant, $attrs + ['name' => 'Budi Santoso']);
        $lead->forceFill(['stage_entered_at' => now()->subHours($hoursAgo)])->save();

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
        $lead = $this->lead(25);

        $this->assertSame(['sent' => 1, 'failed' => 0, 'skipped' => 0], $this->runFollowUps());
        $this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 0], $this->runFollowUps(), 'Second run must not resend');

        $conversation = $this->inTenant(fn () => WaConversation::where('lead_id', $lead->getKey())->first());
        $body = $conversation->messages()->where('is_intro', false)->first()->body;
        $this->assertStringStartsWith('Halo Budi, saya ', $body);
        $this->assertStringContainsString('Griya Prima', $body);
    }

    public function test_not_sent_before_the_delay(): void
    {
        $this->gateway();
        $this->rule();
        $this->lead(5);

        $this->assertSame(0, $this->runFollowUps()['sent']);
    }

    public function test_stale_leads_are_left_alone(): void
    {
        $this->gateway();
        $this->rule();
        $this->lead(24 + RunFollowUps::STALE_AFTER_HOURS + 5);

        $this->assertSame(0, $this->runFollowUps()['sent']);
    }

    public function test_respects_sending_hours_and_sends_later(): void
    {
        $this->gateway();
        $this->rule(['send_from_hour' => 11, 'send_until_hour' => 20]);
        $this->lead(30);

        $this->assertSame(0, $this->runFollowUps()['sent']);

        Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00', 'Asia/Jakarta'));
        $this->assertSame(1, $this->runFollowUps()['sent']);
    }

    public function test_stops_when_the_lead_replied_after_entering_the_stage(): void
    {
        $this->gateway();
        $this->rule();
        $lead = $this->lead(30);
        $this->inTenant(fn () => WaConversation::create(['lead_id' => $lead->getKey(), 'phone' => $lead->phone, 'last_inbound_at' => now()->subHours(2)]));

        $this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 1], $this->runFollowUps());
        $this->assertSame('Lead sudah membalas', FollowUpLog::withoutGlobalScopes()->first()->note);
    }

    public function test_only_rules_for_the_lead_stage_apply_and_moving_stops_them(): void
    {
        $this->gateway();
        $this->rule();
        $lead = $this->lead(30);
        $this->inTenant(fn () => app(MoveLeadToStage::class)($lead->fresh(), $this->stage($this->tenant, 'Dihubungi'), $this->adminOf($this->tenant)));

        $this->assertSame(0, $this->runFollowUps()['sent']);
        $this->assertNotNull($lead->fresh()->stage_entered_at);
    }

    public function test_closed_leads_and_inactive_rules_are_ignored(): void
    {
        $this->gateway();
        $this->rule(['stage_id' => null]);
        $this->rule(['name' => 'Mati', 'stage_id' => null, 'active' => false]);
        $lost = $this->lead(30);
        $lost->forceFill(['stage_id' => $this->stage($this->tenant, 'Gugur')->getKey()])->save();

        $this->assertSame(0, $this->runFollowUps()['sent']);
    }

    public function test_without_a_free_route_nothing_is_sent_or_logged(): void
    {
        $this->rule();
        $this->lead(30);

        $this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 0], $this->runFollowUps());
        $this->assertSame(0, FollowUpLog::withoutGlobalScopes()->count());

        $this->gateway();
        $this->assertSame(1, $this->runFollowUps()['sent'], 'Picked up once a number is connected');
    }

    public function test_one_message_per_lead_per_run(): void
    {
        $this->gateway();
        $this->rule(['name' => 'A', 'stage_id' => null, 'delay_hours' => 24]);
        $this->rule(['name' => 'B', 'stage_id' => null, 'delay_hours' => 30]);
        $this->lead(40);

        $this->assertSame(1, $this->runFollowUps()['sent']);
    }

    public function test_failed_send_is_logged_and_not_retried(): void
    {
        $this->gateway();
        $this->rule();
        $this->lead(30);
        $this->gatewayDown = true;

        $this->assertSame(1, $this->runFollowUps()['failed']);
        $this->assertSame(0, $this->runFollowUps()['failed']);
    }

    public function test_limit_caps_a_single_run(): void
    {
        $this->gateway();
        $this->rule();
        $this->lead(30);
        $this->lead(30, ['phone' => '081200000001']);

        $this->assertSame(1, app(RunFollowUps::class)($this->tenant, 1)['sent']);
    }

    public function test_tenants_do_not_follow_up_each_others_leads(): void
    {
        $other = $this->makeTenant('rumah-lain');
        $this->gateway();
        $this->rule();
        $this->lead(30);

        $this->assertSame(0, app(RunFollowUps::class)($other)['sent']);
    }

    public function test_command_runs_for_every_tenant(): void
    {
        $this->gateway();
        $this->rule();
        $this->lead(30);

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
                'name' => 'Baru', 'delay_hours' => 48, 'body' => 'Halo {nama}',
                'send_from_hour' => 9, 'send_until_hour' => 18, 'active' => true,
            ])->assertHasNoActionErrors();
        $this->assertSame(1, FollowUpRule::where('name', 'Baru')->count());

        $this->actingInTenant($this->member($this->tenant, Role::Agent), $this->tenant);
        $this->assertFalse(auth()->user()->can('viewAny', FollowUpRule::class));
    }

    public function test_sending_hours_must_make_sense(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(ManageFollowUpRules::class)
            ->callAction('create', ['name' => 'X', 'delay_hours' => 24, 'body' => 'Hi', 'send_from_hour' => 18, 'send_until_hour' => 9])
            ->assertHasActionErrors(['send_until_hour']);
    }
}
