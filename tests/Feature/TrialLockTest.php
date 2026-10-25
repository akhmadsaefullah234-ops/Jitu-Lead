<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** An agency whose trial ended unpaid only sees its plan and how to renew; leads keep coming in. */
class TrialLockTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
    }

    private function endTrial(): void
    {
        $this->tenant->currentSubscription()->update(['status' => Subscription::TRIAL, 'trial_ends_at' => now()->subDay(), 'current_period_end' => null]);
    }

    private function url(string $path = ''): string
    {
        return '/app/'.$this->tenant->slug.($path === '' ? '' : '/'.$path);
    }

    public function test_an_admin_of_an_expired_trial_only_reaches_the_plan_page(): void
    {
        $this->endTrial();
        $this->actingAs($this->adminOf($this->tenant));

        foreach (['', 'leads', 'leads/create', 'pipeline', 'inbox', 'tasks', 'reports', 'wa-channels', 'team/teams'] as $path) {
            $this->get($this->url($path))->assertRedirect($this->url('subscription'));
        }

        $this->get($this->url('subscription'))->assertOk()
            ->assertSee('Masa percobaan sudah berakhir, semua menu dikunci.')
            ->assertDontSee($this->url('leads').'"', false)
            ->assertDontSee($this->url('pipeline').'"', false);

        $this->get($this->url('masa-percobaan-berakhir'))->assertOk()->assertSee('Pilih paket dan perpanjang');
    }

    public function test_an_agent_of_an_expired_trial_sees_why_and_who_can_renew(): void
    {
        $this->endTrial();
        $this->actingAs($this->member($this->tenant, Role::Agent));

        $this->get($this->url('leads'))->assertRedirect($this->url('masa-percobaan-berakhir'));
        $this->get($this->url('subscription'))->assertRedirect($this->url('masa-percobaan-berakhir'));

        $this->get($this->url('masa-percobaan-berakhir'))->assertOk()
            ->assertSee('Semua menu dikunci')
            ->assertSee($this->adminOf($this->tenant)->name)
            ->assertDontSee($this->url('inbox').'"', false);
    }

    public function test_new_leads_still_arrive_while_locked(): void
    {
        $this->endTrial();
        $token = app(CurrentTenant::class)->run($this->tenant, fn () => $this->tenant->regenerateCaptureToken());

        $this->postJson("/capture/{$token}", ['name' => 'Masuk saat terkunci', 'phone' => '081200000011'])->assertOk();

        $this->assertTrue(app(CurrentTenant::class)->run($this->tenant, fn () => Lead::query()->where('name', 'Masuk saat terkunci')->exists()));
    }

    public function test_a_running_trial_and_a_paid_plan_are_not_locked(): void
    {
        $this->actingAs($this->adminOf($this->tenant));
        $this->get($this->url('leads'))->assertOk();

        // A paid agency that fell behind stays read-only as before, with its menus open.
        $this->tenant->currentSubscription()->update(['status' => Subscription::READ_ONLY, 'plan' => 'tim', 'current_period_end' => now()->subDays(5)]);
        $this->get($this->url('leads'))->assertOk();
    }

    public function test_paying_lifts_the_lock(): void
    {
        $this->endTrial();
        $this->actingAs($this->adminOf($this->tenant));
        $this->get($this->url('leads'))->assertRedirect();

        $this->tenant->currentSubscription()->update(['status' => Subscription::ACTIVE, 'plan' => 'tim', 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);

        $this->get($this->url('leads'))->assertOk();
        $this->get($this->url('masa-percobaan-berakhir'))->assertOk()->assertSee('Langganan agensi aktif');
    }
}
