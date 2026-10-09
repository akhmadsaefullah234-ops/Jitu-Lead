<?php

namespace Tests\Feature;

use App\Billing\PlatformStats;
use App\Enums\Role;
use App\Filament\Admin\Resources\RegistrationInvites\Pages\ManageRegistrationInvites;
use App\Filament\Admin\Resources\SubscriptionRequests\Pages\ListSubscriptionRequests;
use App\Filament\Admin\Resources\SupportThreads\Pages\ViewSupportThread;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Widgets\PlatformOverview;
use App\Filament\Admin\Widgets\SignupsChart;
use App\Filament\Admin\Widgets\TrialsEnding;
use App\Livewire\SupportChat;
use App\Mail\SupportMessageReceived;
use App\Models\RegistrationInvite;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $owner->forceFill(['is_super_admin' => true])->save();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($owner);

        return $owner;
    }

    // --- access ---

    public function test_only_super_admins_get_into_the_admin_panel(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');

        $tenant = $this->makeTenant();
        $this->actingAs($this->adminOf($tenant))->get('/admin')->assertForbidden();

        $this->owner();
        $this->get('/admin')->assertOk()->assertSee('Dasbor Super Admin');
    }

    public function test_a_normal_user_cannot_reach_any_admin_page(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->adminOf($tenant));

        foreach (['tenants', 'subscription-requests', 'registration-invites', 'support-threads', 'users'] as $page) {
            $this->get("/admin/$page")->assertForbidden();
        }
    }

    public function test_every_admin_page_opens_for_the_owner(): void
    {
        $this->makeTenant();
        $this->owner();

        foreach (['tenants', 'subscription-requests', 'registration-invites', 'support-threads', 'users'] as $page) {
            $this->get("/admin/$page")->assertOk();
        }
    }

    public function test_the_make_admin_command_creates_promotes_and_revokes(): void
    {
        $this->artisan('admin:make', ['email' => 'Boss@Example.com', '--name' => 'Bos', '--password' => 'kata-sandi-panjang'])->assertSuccessful();
        $boss = User::where('email', 'boss@example.com')->first();
        $this->assertTrue($boss->is_super_admin);
        $this->assertNotNull($boss->email_verified_at);

        $this->artisan('admin:make', ['email' => 'boss@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($boss->fresh()->is_super_admin);

        $this->artisan('admin:make', ['email' => 'baru@example.com', '--password' => 'pendek'])->assertFailed();
    }

    // --- numbers ---

    public function test_platform_stats_count_trials_paid_and_estimated_revenue(): void
    {
        $trial = $this->makeTenant('trial-co');
        $ended = $this->makeTenant('ended-co');
        $ended->currentSubscription()->update(['trial_ends_at' => now()->subDay()]);
        $monthly = $this->makeTenant('monthly-co');
        $monthly->currentSubscription()->update(['plan' => 'tim', 'status' => 'active', 'billing_cycle' => 'monthly', 'current_period_end' => now()->addMonth()]);
        $yearly = $this->makeTenant('yearly-co');
        $yearly->currentSubscription()->update(['plan' => 'mandiri', 'status' => 'active', 'billing_cycle' => 'yearly', 'current_period_end' => now()->addYear()]);

        $s = PlatformStats::summary();

        $this->assertSame(4, $s['tenants']);
        $this->assertSame(1, $s['trial']);
        $this->assertSame(1, $s['trial_ended']);
        $this->assertSame(2, $s['active']);
        $this->assertSame(199000 + (int) round(790000 / 12), $s['mrr']);
        $this->assertSame(4, $s['new_7d']);
        $this->assertSame(1, array_sum(array_slice(PlatformStats::signupsPerDay(30), -1)) > 0 ? 1 : 0);
        $this->assertNotNull($trial);
    }

    public function test_dashboard_widgets_render(): void
    {
        $this->makeTenant();
        $this->owner();

        Livewire::test(PlatformOverview::class)->assertSee('Total agensi')->assertSee('Chat belum dibalas');
        Livewire::test(SignupsChart::class)->assertOk();
        Livewire::test(TrialsEnding::class)->assertOk();
    }

    // --- agency actions ---

    public function test_owner_activates_a_plan_extends_a_trial_adds_addons_and_suspends(): void
    {
        $tenant = $this->makeTenant();
        $this->owner();

        Livewire::test(ListTenants::class)
            ->callTableAction('setPlan', $tenant, ['plan' => 'agensi', 'cycle' => 'yearly', 'months' => 12])
            ->assertHasNoTableActionErrors();
        $sub = $tenant->currentSubscription()->fresh();
        $this->assertSame(['agensi', 'active'], [$sub->plan, $sub->status]);

        $other = $this->makeTenant('other-co');
        $other->currentSubscription()->update(['trial_ends_at' => now()->subDays(2)]);
        Livewire::test(ListTenants::class)->callTableAction('extendTrial', $other, ['days' => 7]);
        $this->assertTrue($other->currentSubscription()->fresh()->trial_ends_at->isFuture());
        $this->assertFalse($other->currentSubscription()->fresh()->isReadOnly());

        Livewire::test(ListTenants::class)->callTableAction('addon', $tenant, ['kind' => 'ai', 'qty' => 2]);
        $this->assertSame(2, $tenant->currentSubscription()->fresh()->addon_ai);

        $admin = $this->adminOf($tenant);
        $this->assertTrue($admin->canAccessTenant($tenant));
        Livewire::test(ListTenants::class)->callTableAction('suspend', $tenant);
        $this->assertFalse($admin->fresh()->canAccessTenant($tenant->fresh()));
        Livewire::test(ListTenants::class)->callTableAction('unsuspend', $tenant);
        $this->assertTrue($admin->fresh()->canAccessTenant($tenant->fresh()));
    }

    public function test_extend_trial_is_hidden_for_paying_agencies(): void
    {
        $tenant = $this->makeTenant();
        $tenant->currentSubscription()->update(['plan' => 'tim', 'status' => 'active', 'current_period_end' => now()->addMonth()]);
        $this->owner();

        Livewire::test(ListTenants::class)->assertTableActionHidden('extendTrial', $tenant);
    }

    public function test_the_list_is_filterable_by_state_and_shows_all_agencies(): void
    {
        $a = $this->makeTenant('aaa-co');
        $b = $this->makeTenant('bbb-co');
        $b->update(['status' => 'suspended']);
        $this->owner();

        Livewire::test(ListTenants::class)->assertCanSeeTableRecords([$a, $b])
            ->filterTable('state', 'suspended')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
    }

    public function test_approving_a_request_activates_the_plan_and_rejecting_cancels_it(): void
    {
        $tenant = $this->makeTenant();
        $req = SubscriptionRequest::create(['tenant_id' => $tenant->getKey(), 'plan' => 'tim', 'billing_cycle' => 'yearly', 'amount' => 1990000]);
        $other = $this->makeTenant('other-co');
        $rej = SubscriptionRequest::create(['tenant_id' => $other->getKey(), 'plan' => 'mandiri', 'billing_cycle' => 'monthly', 'amount' => 79000]);
        $this->owner();

        Livewire::test(ListSubscriptionRequests::class)->callTableAction('approve', $req)->callTableAction('reject', $rej);

        $sub = $tenant->currentSubscription()->fresh();
        $this->assertSame(['tim', 'active', 'yearly'], [$sub->plan, $sub->status, $sub->billing_cycle]);
        $this->assertEqualsWithDelta(12, now()->diffInMonths($sub->current_period_end), 1);
        $this->assertSame(SubscriptionRequest::APPROVED, $req->fresh()->status);
        $this->assertSame(SubscriptionRequest::CANCELLED, $rej->fresh()->status);
        $this->assertSame(Subscription::TRIAL, $other->currentSubscription()->fresh()->status);
    }

    public function test_owner_creates_invite_codes_and_verifies_emails(): void
    {
        $this->owner();

        Livewire::test(ManageRegistrationInvites::class)->callAction('create', ['max_uses' => 3, 'days' => 0, 'note' => 'Pak Budi']);
        $invite = RegistrationInvite::first();
        $this->assertSame([3, null, 'Pak Budi'], [$invite->max_uses, $invite->expires_at, $invite->note]);

        $user = User::factory()->unverified()->create();
        Livewire::test(ListUsers::class)->callTableAction('verify', $user);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    // --- support chat ---

    private function chat(User $user, $tenant)
    {
        $this->actingInTenant($user, $tenant);
        RateLimiter::clear('support-chat:'.$user->getKey());

        return Livewire::test(SupportChat::class);
    }

    public function test_a_user_writes_and_the_owner_replies(): void
    {
        Mail::fake();
        config(['jitu.support_email' => 'bantuan@example.com']);
        $tenant = $this->makeTenant();
        $agent = $this->member($tenant, Role::Agent);

        $this->chat($agent, $tenant)->call('toggle')->set('body', "Tidak bisa impor <b>lead</b>\nmohon bantu")->call('send')->assertSee('Tidak bisa impor');
        Mail::assertSent(SupportMessageReceived::class, fn ($m) => $m->hasTo('bantuan@example.com'));

        $thread = SupportThread::withoutGlobalScopes()->first();
        $this->assertTrue($thread->staff_unread);
        $this->assertSame($agent->getKey(), $thread->user_id);

        $this->owner();
        app(CurrentTenant::class)->set(null);
        $this->assertSame(1, PlatformStats::unansweredChats());
        Livewire::test(ViewSupportThread::class, ['record' => $thread->getKey()])->set('reply', 'Sudah kami cek, coba lagi ya.')->call('send');
        $thread->refresh();
        $this->assertFalse($thread->staff_unread);
        $this->assertTrue($thread->user_unread);
        $this->assertSame(0, PlatformStats::unansweredChats());

        $this->chat($agent, $tenant)->assertSee('!', false)->call('toggle')->assertSee('Sudah kami cek')->assertSee('Tim support');
        $this->assertFalse($thread->fresh()->user_unread);
    }

    public function test_chat_text_is_escaped(): void
    {
        $tenant = $this->makeTenant();
        $c = $this->chat($this->adminOf($tenant), $tenant)->call('toggle')->set('body', '<script>alert(1)</script>')->call('send');

        $c->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_chats_are_private_between_colleagues_and_between_agencies(): void
    {
        $t1 = $this->makeTenant('one-co');
        $t2 = $this->makeTenant('two-co');
        $a1 = $this->member($t1, Role::Agent);
        $a2 = $this->member($t1, Role::Agent);
        $b1 = $this->adminOf($t2);

        $this->chat($a1, $t1)->call('toggle')->set('body', 'rahasia agen satu')->call('send');

        $this->chat($a2, $t1)->call('toggle')->assertDontSee('rahasia agen satu');
        $this->chat($b1, $t2)->call('toggle')->assertDontSee('rahasia agen satu');
    }

    public function test_sending_is_rate_limited_and_empty_messages_are_ignored(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->adminOf($tenant);
        $c = $this->chat($user, $tenant)->call('toggle')->set('body', '   ')->call('send');
        $this->assertSame(0, SupportThread::withoutGlobalScopes()->count());

        for ($i = 0; $i < 10; $i++) {
            $c->set('body', "pesan $i")->call('send');
        }

        $this->assertSame(8, SupportMessage::withoutGlobalScopes()->count());
        $c->assertSee('Terlalu cepat');
    }

    public function test_a_closed_chat_reopens_when_the_user_writes_again(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->adminOf($tenant);
        $this->chat($user, $tenant)->call('toggle')->set('body', 'halo')->call('send');
        $thread = SupportThread::withoutGlobalScopes()->first();
        $thread->update(['status' => SupportThread::CLOSED]);

        $this->chat($user, $tenant)->call('toggle')->assertSee('ditandai selesai')->set('body', 'masih ada masalah')->call('send');

        $this->assertSame(SupportThread::OPEN, $thread->fresh()->status);
        $this->assertSame(1, SupportThread::withoutGlobalScopes()->count());
    }

    public function test_the_chat_button_is_on_every_agency_page(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->adminOf($tenant))->get('/app/'.$tenant->slug)->assertOk()->assertSee('Buka chat bantuan', false);
    }
}
