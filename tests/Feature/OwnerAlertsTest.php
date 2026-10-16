<?php

namespace Tests\Feature;

use App\Billing\PlanRequests;
use App\Filament\Pages\Tenancy\RegisterAgency;
use App\Mail\NewAgencyRegistered;
use App\Mail\SubscriptionRequested;
use App\Models\PushSubscription;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Push\PushTransport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Records what would be pushed instead of calling the browsers' push services. */
class OwnerAlertsPushTransport extends PushTransport
{
    /** @var list<array{users: list<int>, payload: array<string, string>}> */
    public array $sent = [];

    public function enabled(): bool
    {
        return true;
    }

    public function send(Collection $subscriptions, array $payload): array
    {
        $this->sent[] = ['users' => $subscriptions->pluck('user_id')->unique()->sort()->values()->all(), 'payload' => $payload];

        return $subscriptions->mapWithKeys(fn ($s) => [$s->getKey() => 'ok'])->all();
    }
}

class OwnerAlertsTest extends TestCase
{
    use RefreshDatabase;

    private OwnerAlertsPushTransport $push;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['jitu.support_email' => 'owner@example.com', 'jitu.notify_signups' => true]);
        $this->push = new OwnerAlertsPushTransport;
        $this->app->instance(PushTransport::class, $this->push);

        $this->owner = User::factory()->create(['email' => 'owner@example.com']);
        $this->owner->forceFill(['is_super_admin' => true])->save();
        $this->subscribe($this->owner);
    }

    private function subscribe(User $user): void
    {
        $endpoint = "https://fcm.googleapis.com/fcm/send/{$user->getKey()}-a";
        PushSubscription::create(['user_id' => $user->getKey(), 'endpoint_hash' => PushSubscription::hash($endpoint), 'endpoint' => $endpoint, 'p256dh' => 'BKey', 'auth' => 'authkey', 'device' => 'Android · Chrome']);
    }

    private function register(string $name = 'Griya Baru', string $slug = 'griya-baru'): void
    {
        $user = User::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@example.com']);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        Livewire::test(RegisterAgency::class)->fillForm(['name' => $name, 'slug' => $slug])->call('register')->assertHasNoFormErrors();
    }

    public function test_a_new_agency_emails_the_owner_and_pushes_to_super_admins(): void
    {
        $this->register();

        Mail::assertQueued(NewAgencyRegistered::class, function (NewAgencyRegistered $m) {
            $this->assertTrue($m->hasTo('owner@example.com'));
            $this->assertSame('Griya Baru', $m->details['Agensi']);
            $this->assertSame('Budi Santoso', $m->details['Pendaftar']);
            $this->assertSame('budi@example.com', $m->details['Email']);
            $this->assertStringContainsString('Percobaan gratis', $m->details['Mode pendaftaran']);
            $this->assertNotEmpty($m->details['Percobaan berakhir']);
            $this->assertStringContainsString('/admin/tenants', $m->url);

            return true;
        });

        $this->assertCount(1, $this->push->sent);
        $this->assertSame([$this->owner->getKey()], $this->push->sent[0]['users']);
        $this->assertSame('Agensi baru: Griya Baru (budi@example.com)', $this->push->sent[0]['payload']['title']);
    }

    public function test_the_email_text_lists_the_details_and_the_link(): void
    {
        $this->register();

        Mail::assertQueued(NewAgencyRegistered::class, function (NewAgencyRegistered $m) {
            $text = $m->render();
            $this->assertStringContainsString('Griya Baru', $text);
            $this->assertStringContainsString('budi@example.com', $text);
            $this->assertStringContainsString('/admin/tenants', $text);

            return true;
        });
    }

    public function test_a_plan_request_emails_the_owner_and_pushes_to_super_admins(): void
    {
        $tenant = $this->makeTenant('griya-prima');
        $admin = $this->adminOf($tenant);
        $tenant->currentSubscription()->update(['addon_ai' => 2]);

        PlanRequests::create($tenant, $admin, 'tim', 'yearly');

        Mail::assertQueued(SubscriptionRequested::class, function (SubscriptionRequested $m) {
            $this->assertTrue($m->hasTo('owner@example.com'));
            $this->assertSame('Griya Prima', $m->details['Agensi']);
            $this->assertSame('Tim Kecil', $m->details['Paket']);
            $this->assertSame('Tahunan', $m->details['Periode']);
            $this->assertSame('Rp 1.990.000', $m->details['Total yang harus ditransfer']);
            $this->assertStringContainsString('x 2', $m->details['Add-on saat ini']);
            $this->assertStringContainsString('/admin/subscription-requests', $m->url);

            return true;
        });

        $this->assertCount(1, $this->push->sent);
        $this->assertSame('Permintaan paket Tim Kecil dari Griya Prima — Rp 1.990.000', $this->push->sent[0]['payload']['title']);
    }

    public function test_only_super_admins_get_the_push_not_agency_admins(): void
    {
        $tenant = $this->makeTenant('griya-prima');
        $admin = $this->adminOf($tenant);
        $this->subscribe($admin);

        PlanRequests::create($tenant, $admin, 'mandiri', 'monthly');

        $this->assertSame([$this->owner->getKey()], $this->push->sent[0]['users']);
    }

    public function test_without_a_support_email_the_mail_is_skipped_and_the_push_still_goes(): void
    {
        config(['jitu.support_email' => null]);
        $tenant = $this->makeTenant('griya-prima');

        PlanRequests::create($tenant, $this->adminOf($tenant), 'mandiri', 'monthly');

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $this->assertCount(1, $this->push->sent);
    }

    public function test_notify_signups_false_switches_both_notifications_off(): void
    {
        config(['jitu.notify_signups' => false]);

        $this->register();
        $tenant = $this->makeTenant('griya-prima');
        PlanRequests::create($tenant, $this->adminOf($tenant), 'mandiri', 'monthly');

        Mail::assertNothingQueued();
        $this->assertSame([], $this->push->sent);
    }

    public function test_a_failing_notification_never_breaks_the_request_or_the_sign_up(): void
    {
        Exceptions::fake();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('mail down'));

        $this->register();
        $tenant = $this->makeTenant('griya-prima');
        $request = PlanRequests::create($tenant, $this->adminOf($tenant), 'mandiri', 'monthly');

        $this->assertNotNull($request);
        $this->assertSame(1, SubscriptionRequest::query()->where('status', 'pending')->count());
        $this->assertDatabaseHas('tenants', ['slug' => 'griya-baru']);
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'mail down');
    }
}
