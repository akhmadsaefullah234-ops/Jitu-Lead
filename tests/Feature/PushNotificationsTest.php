<?php

namespace Tests\Feature;

use App\Actions\RegisterLead;
use App\Enums\Role;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Notifications;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Jobs\SendPushAlert;
use App\Models\Lead;
use App\Models\PushSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Push\PushTransport;
use App\Support\CurrentTenant;
use App\WhatsApp\GatewayUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Records what would be pushed instead of calling the browsers' push services. */
class FakePushTransport extends PushTransport
{
    /** @var list<array{users: list<int>, payload: array<string, string>}> */
    public array $sent = [];

    public string $result = 'ok';

    public function enabled(): bool
    {
        return true;
    }

    public function send(Collection $subscriptions, array $payload): array
    {
        $this->sent[] = ['users' => $subscriptions->pluck('user_id')->unique()->sort()->values()->all(), 'payload' => $payload];

        return $subscriptions->mapWithKeys(fn ($s) => [$s->getKey() => $this->result])->all();
    }
}

class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $agent;

    private FakePushTransport $push;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->admin = $this->adminOf($this->tenant);
        $this->agent = $this->member($this->tenant, Role::Agent);
        $this->push = new FakePushTransport;
        $this->app->instance(PushTransport::class, $this->push);
        config(['whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function subscribe(User $user, string $path = 'a'): PushSubscription
    {
        $endpoint = "https://fcm.googleapis.com/fcm/send/{$user->getKey()}-{$path}";

        return PushSubscription::create(['user_id' => $user->getKey(), 'endpoint_hash' => PushSubscription::hash($endpoint), 'endpoint' => $endpoint, 'p256dh' => 'BKey', 'auth' => 'authkey', 'device' => 'Android · Chrome']);
    }

    private function capture(array $data): void
    {
        $token = $this->tenant->regenerateCaptureToken();
        $this->postJson("/capture/{$token}", $data)->assertOk();
    }

    private function gateway(): WaChannel
    {
        return app(CurrentTenant::class)->run($this->tenant, fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002', 'status' => WaChannelStatus::Connected, 'position' => 1,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function whatsapp(WaChannel $gw, string $from, string $id, string $text = 'Info rumah'): void
    {
        $body = json_encode(['event' => 'message', 'id' => $id, 'from' => $from, 'type' => 'text', 'text' => $text, 'timestamp' => time(), 'name' => 'Sari']);

        $this->call('POST', "/webhooks/whatsapp/gateway/{$gw->webhook_token}", [], [], [], $this->transformHeadersToServerVars([
            'CONTENT_TYPE' => 'application/json', 'X-Gateway-Signature' => 'sha256='.hash_hmac('sha256', $body, 's'),
        ]), $body)->assertOk();
    }

    public function test_a_web_form_lead_goes_to_the_admin_and_the_owner_only(): void
    {
        $other = $this->member($this->tenant, Role::Agent);
        foreach ([$this->admin, $this->agent, $other] as $u) {
            $this->subscribe($u);
        }

        $this->capture(['name' => 'Dewi Lestari', 'phone' => '081299990000', 'note' => 'Tanya KPR']);

        $lead = Lead::withoutGlobalScopes()->where('name', 'Dewi Lestari')->firstOrFail();
        $this->assertCount(1, $this->push->sent);
        $expected = collect([$this->admin->getKey(), $lead->owner_id])->unique()->sort()->values()->all();
        $this->assertSame($expected, $this->push->sent[0]['users']);
        $this->assertNotContains($lead->owner_id === $this->agent->getKey() ? $other->getKey() : $this->agent->getKey(), $this->push->sent[0]['users']);

        $payload = $this->push->sent[0]['payload'];
        $this->assertSame('Lead baru dari formulir web', $payload['title']);
        $this->assertStringContainsString('Dewi Lestari', $payload['body']);
        $this->assertStringContainsString('Tanya KPR', $payload['body']);
        $this->assertStringStartsWith('https://', str_replace('http://', 'https://', $payload['url']));
        $this->assertStringContainsString("/leads/{$lead->getKey()}/edit", $payload['url']);
    }

    public function test_people_can_switch_lead_alerts_off_and_a_repeat_contact_is_not_a_new_lead(): void
    {
        $this->subscribe($this->admin);
        $this->admin->forceFill(['notify_leads' => false])->save();
        $this->capture(['name' => 'Dewi', 'phone' => '081299990000']);
        $this->assertSame([], $this->push->sent);

        $this->admin->forceFill(['notify_leads' => true])->save();
        $this->capture(['name' => 'Dewi lagi', 'phone' => '081299990000']); // same phone: logged on the existing lead
        $this->assertSame([], $this->push->sent);
    }

    public function test_whatsapp_new_lead_and_later_messages(): void
    {
        $gw = $this->gateway();
        $this->subscribe($this->admin);
        $this->subscribe($this->agent);

        $this->whatsapp($gw, '081211112222', 'm1', 'Halo, ada unit tipe 36?');
        $lead = Lead::withoutGlobalScopes()->where('phone', '+6281211112222')->firstOrFail();
        $this->assertCount(1, $this->push->sent);
        $this->assertSame('Lead baru dari WhatsApp', $this->push->sent[0]['payload']['title']);
        $this->assertStringContainsString('Halo, ada unit tipe 36?', $this->push->sent[0]['payload']['body']);

        $this->whatsapp($gw, '081211112222', 'm2', 'Berapa harganya?');
        $this->assertCount(2, $this->push->sent);
        $message = $this->push->sent[1];
        $this->assertSame([$lead->owner_id], $message['users'], 'A message goes to the lead owner only');
        $this->assertSame('Pesan WhatsApp dari Sari', $message['payload']['title']);
        $this->assertSame('Berapa harganya?', $message['payload']['body']);
        $this->assertStringContainsString("lead={$lead->getKey()}", $message['payload']['url']);
    }

    public function test_messages_from_a_lead_without_owner_go_to_the_admins(): void
    {
        $gw = $this->gateway();
        $this->whatsapp($gw, '081233334444', 'm1');
        $lead = Lead::withoutGlobalScopes()->where('phone', '+6281233334444')->firstOrFail();
        $lead->forceFill(['owner_id' => null])->save();
        $this->subscribe($this->admin);
        $this->subscribe($this->agent);
        $this->push->sent = [];

        $this->whatsapp($gw, '081233334444', 'm2');

        $this->assertSame([$this->admin->getKey()], $this->push->sent[0]['users']);
    }

    public function test_message_alerts_respect_the_preference_and_a_broken_alert_never_blocks_the_message(): void
    {
        $gw = $this->gateway();
        $this->subscribe($this->admin);
        $this->whatsapp($gw, '081255556666', 'm1');
        $lead = Lead::withoutGlobalScopes()->where('phone', '+6281255556666')->firstOrFail();
        $owner = User::findOrFail($lead->owner_id);
        $this->subscribe($owner);
        $owner->forceFill(['notify_messages' => false])->save();
        $this->push->sent = [];

        $this->whatsapp($gw, '081255556666', 'm2');
        $this->assertSame([], $this->push->sent);

        $owner->forceFill(['notify_messages' => true])->save();
        Queue::shouldReceive('push')->never();
        $this->app->instance(PushTransport::class, new class extends FakePushTransport
        {
            public function send(Collection $subscriptions, array $payload): array
            {
                throw new \RuntimeException('push service down');
            }
        });
        $this->whatsapp($gw, '081255556666', 'm3');
        $this->assertSame(3, WaMessage::withoutGlobalScopes()->where('direction', 'in')->count(), 'The message is stored even if the alert fails');
    }

    public function test_the_job_forgets_devices_the_push_service_says_are_gone(): void
    {
        $gone = $this->subscribe($this->agent, 'gone');
        $this->push->result = 'gone';

        (new SendPushAlert([$this->agent->getKey()], ['title' => 't', 'body' => 'b', 'url' => '/app']))->handle($this->push);

        $this->assertFalse(PushSubscription::query()->whereKey($gone->getKey())->exists());

        $kept = $this->subscribe($this->agent, 'kept');
        $this->push->result = 'ok';
        (new SendPushAlert([$this->agent->getKey()], ['title' => 't', 'body' => 'b', 'url' => '/app']))->handle($this->push);
        $this->assertNotNull($kept->fresh()->last_sent_at);
    }

    public function test_the_page_registers_lists_and_removes_devices_for_every_role(): void
    {
        foreach ([$this->admin, $this->agent] as $user) {
            $this->actingInTenant($user, $this->tenant);
            $page = Livewire::test(Notifications::class)->assertOk();

            $ok = $page->instance()->saveSubscription(['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'.$user->getKey(), 'keys' => ['p256dh' => 'BPublicKey_-1', 'auth' => 'auth_key-1']], 'Mozilla/5.0 (Linux; Android 14) Chrome/120 Mobile');
            $this->assertTrue($ok);
            $this->assertSame('Android · Chrome', $user->pushSubscriptions()->first()->device);
        }

        $this->actingInTenant($this->agent, $this->tenant);
        $page = Livewire::test(Notifications::class);
        $this->assertCount(1, $page->instance()->devices(), 'Only my own devices are listed');
        $page->call('removeDevice', $this->admin->pushSubscriptions()->first()->getKey());
        $this->assertSame(1, $this->admin->pushSubscriptions()->count(), 'Cannot remove someone else\'s device');
        $page->call('removeDevice', $this->agent->pushSubscriptions()->first()->getKey());
        $this->assertSame(0, $this->agent->pushSubscriptions()->count());
    }

    public function test_the_page_refuses_subscriptions_that_are_not_real_push_services(): void
    {
        $this->actingInTenant($this->agent, $this->tenant);
        $page = Livewire::test(Notifications::class)->instance();
        $keys = ['p256dh' => 'BPublicKey_-1', 'auth' => 'auth_key-1'];

        foreach (['http://fcm.googleapis.com/x', 'https://evil.example.com/x', 'https://127.0.0.1/x', 'https://169.254.169.254/latest', 'https://googleapis.com.evil.com/x', 'https://user@fcm.googleapis.com/x', 'https://fcm.googleapis.com:8443/x'] as $endpoint) {
            $this->assertFalse($page->saveSubscription(['endpoint' => $endpoint, 'keys' => $keys]), $endpoint);
        }
        $this->assertFalse($page->saveSubscription(['endpoint' => 'https://fcm.googleapis.com/x', 'keys' => ['p256dh' => '<script>', 'auth' => 'a']]));
        $this->assertSame(0, PushSubscription::count());

        foreach (['https://fcm.googleapis.com/fcm/send/x', 'https://updates.push.services.mozilla.com/wpush/v2/x', 'https://web.push.apple.com/x', 'https://wns2-par02p.notify.windows.com/w/x'] as $endpoint) {
            $this->assertTrue(PushTransport::allowedEndpoint($endpoint), $endpoint);
        }
    }

    public function test_a_shared_browser_moves_to_the_person_who_enabled_it_last_and_devices_are_capped(): void
    {
        $this->actingInTenant($this->admin, $this->tenant);
        $endpoint = 'https://fcm.googleapis.com/fcm/send/shared';
        $sub = ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BPublicKey_-1', 'auth' => 'auth_key-1']];
        Livewire::test(Notifications::class)->instance()->saveSubscription($sub);

        $this->actingInTenant($this->agent, $this->tenant);
        $page = Livewire::test(Notifications::class)->instance();
        $page->saveSubscription($sub);
        $this->assertSame($this->agent->getKey(), PushSubscription::firstOrFail()->user_id);

        foreach (range(1, 12) as $i) {
            $page->saveSubscription(['endpoint' => "https://fcm.googleapis.com/fcm/send/dev{$i}", 'keys' => $sub['keys']]);
        }
        $this->assertSame(10, $this->agent->pushSubscriptions()->count());
    }

    public function test_preferences_are_saved_and_the_test_notification_reaches_my_devices(): void
    {
        $this->subscribe($this->agent);
        $this->subscribe($this->admin);
        $this->actingInTenant($this->agent, $this->tenant);

        Livewire::test(Notifications::class)
            ->set('notifyLeads', false)->set('notifyMessages', false);
        $this->assertFalse($this->agent->fresh()->notify_leads);
        $this->assertFalse($this->agent->fresh()->notify_messages);

        Livewire::test(Notifications::class)->assertSet('notifyLeads', false)->call('sendTest');
        $this->assertSame([$this->agent->getKey()], $this->push->sent[0]['users']);
        $this->assertSame('Tes notifikasi JITU LEAD', $this->push->sent[0]['payload']['title']);
    }

    public function test_imports_and_manual_leads_do_not_notify(): void
    {
        $this->subscribe($this->admin);
        $this->actingInTenant($this->admin, $this->tenant);

        Livewire::test(CreateLead::class)->fillForm(['name' => 'Manual', 'phone' => '081377778888'])->call('create');
        app(CurrentTenant::class)->run($this->tenant, fn () => app(RegisterLead::class)(['name' => 'Impor', 'phone' => '081366667777'], 'Impor CSV', 'import'));

        $this->assertSame([], $this->push->sent);
    }

    public function test_keys_command_creates_vapid_keys_once(): void
    {
        $env = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($env, "APP_NAME=x\nVAPID_PUBLIC_KEY=\nVAPID_PRIVATE_KEY=\n");

        $this->artisan('push:keys', ['--env' => $env])->assertSuccessful();
        $contents = file_get_contents($env);

        $this->assertMatchesRegularExpression('/^VAPID_PUBLIC_KEY=[A-Za-z0-9_-]{80,}$/m', $contents);
        $this->assertMatchesRegularExpression('/^VAPID_PRIVATE_KEY=[A-Za-z0-9_-]{40,}$/m', $contents);
        $this->assertSame(1, substr_count($contents, 'VAPID_PUBLIC_KEY='));

        config(['jitu.push.public_key' => 'sudah', 'jitu.push.private_key' => 'ada']);
        $before = file_get_contents($env);
        $this->artisan('push:keys', ['--env' => $env])->assertSuccessful();
        $this->assertSame($before, file_get_contents($env), 'Existing keys are never replaced');
        unlink($env);
    }

    public function test_app_shell_files_are_in_place_and_the_worker_only_opens_this_site(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/app', $manifest['start_url']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
        $this->assertContains('192x192', array_column($manifest['icons'], 'sizes'));
        $this->assertContains('512x512', array_column($manifest['icons'], 'sizes'));
        $this->assertFileExists(public_path('images/apple-touch-icon.png'));
        $this->assertFileExists(public_path('images/badge-96.png'));

        $worker = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("addEventListener('push'", $worker);
        $this->assertStringContainsString('url.origin === self.location.origin', $worker);
    }

    public function test_panel_pages_link_the_manifest_and_register_the_worker(): void
    {
        $this->actingInTenant($this->admin, $this->tenant);

        $this->get("/app/{$this->tenant->slug}")->assertOk()
            ->assertSee('manifest.webmanifest', false)
            ->assertSee('apple-touch-icon', false)
            ->assertSee("register('/sw.js')", false);
    }
}
