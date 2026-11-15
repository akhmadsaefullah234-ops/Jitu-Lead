<?php

namespace Tests\Feature;

use App\Enums\WaChannelType;
use App\Jobs\QueueCanary;
use App\Models\WaChannel;
use App\Ops\HealthChecks;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Operator heartbeat (docs/ops-monitoring.md): a ping only while every check passes; the URL never in a log. */
class OpsHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://uptime.betterstack.test/api/v1/heartbeat/s3cr3t-t0ken';

    private const GATEWAY_HEALTH = 'http://127.0.0.1:3100/health';

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jitu.ops.heartbeat_url' => self::URL,
            // The test machine's own disk is not what is tested here (see test_low_disk_space_stops_the_ping).
            'jitu.ops.disk_min_percent' => 0,
            'whatsapp.gateway' => ['url' => 'http://127.0.0.1:3100', 'api_key' => 'k', 'signing_secret' => 's'],
        ]);
        Cache::put(HealthChecks::QUEUE_KEY, now()->getTimestamp(), now()->addDay());

        $tenant = $this->makeTenant();
        $this->actingInTenant($this->adminOf($tenant), $tenant);
        WaChannel::create(['type' => WaChannelType::Gateway, 'name' => 'WA', 'status' => 'connected', 'credentials' => []]);

        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e;
        });
    }

    private function healthyGateway(): void
    {
        Http::fake([
            self::GATEWAY_HEALTH => Http::response(['ok' => true, 'sessions' => 1]),
            'uptime.betterstack.test/*' => Http::response('OK'),
        ]);
    }

    private function pings(): int
    {
        return Http::recorded(fn (Request $r) => $r->url() === self::URL)->count();
    }

    /** @return list<string> */
    private function errors(): array
    {
        return collect($this->logged)->where('level', 'error')->pluck('message')->all();
    }

    private function assertUrlNeverLogged(): void
    {
        foreach ($this->logged as $e) {
            $this->assertStringNotContainsString('s3cr3t-t0ken', $e->message.json_encode($e->context));
        }
    }

    public function test_all_healthy_sends_one_ping(): void
    {
        $this->healthyGateway();

        $this->artisan('ops:heartbeat', ['--force' => true])->assertSuccessful();

        $this->assertSame(1, $this->pings());
        Http::assertSent(fn (Request $r) => $r->url() === self::URL && $r->method() === 'GET');
        $this->assertSame([], $this->errors());
    }

    public function test_a_stale_queue_canary_stops_the_ping_and_logs_an_error(): void
    {
        $this->healthyGateway();
        Cache::put(HealthChecks::QUEUE_KEY, now()->subMinutes(6)->getTimestamp(), now()->addDay());

        $this->artisan('ops:heartbeat', ['--force' => true])->assertFailed();

        $this->assertSame(0, $this->pings());
        $this->assertCount(1, $this->errors());
        $this->assertStringContainsString('queue', $this->errors()[0]);
        $this->assertUrlNeverLogged();
    }

    public function test_a_failing_or_silent_gateway_stops_the_ping(): void
    {
        Http::fake([self::GATEWAY_HEALTH => Http::response(['ok' => false], 503), 'uptime.betterstack.test/*' => Http::response('OK')]);
        $this->artisan('ops:heartbeat', ['--force' => true])->assertFailed();
        $this->assertSame(0, $this->pings());

        Http::fake([self::GATEWAY_HEALTH => fn () => throw new ConnectionException('Operation timed out'), 'uptime.betterstack.test/*' => Http::response('OK')]);
        $this->artisan('ops:heartbeat', ['--force' => true])->assertFailed();
        $this->assertSame(0, $this->pings());

        $this->assertStringContainsString('gateway', $this->errors()[1]);
    }

    public function test_the_gateway_is_skipped_without_qr_numbers(): void
    {
        WaChannel::query()->withoutGlobalScopes()->delete();
        Http::fake(['uptime.betterstack.test/*' => Http::response('OK')]);

        $this->artisan('ops:heartbeat', ['--force' => true])->assertSuccessful();

        $this->assertSame(1, $this->pings());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/health'));
    }

    public function test_too_many_failed_jobs_stop_the_ping(): void
    {
        $this->healthyGateway();
        foreach (range(1, 20) as $i) {
            DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
                'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subMinutes(5)]);
        }
        // Older failures do not count.
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subHour()]);

        $this->artisan('ops:heartbeat', ['--force' => true])->assertFailed();

        $this->assertSame(0, $this->pings());
        $this->assertStringContainsString('failed_jobs (20 job gagal', $this->errors()[0]);
    }

    public function test_low_disk_space_stops_the_ping(): void
    {
        $this->healthyGateway();
        config(['jitu.ops.disk_min_percent' => 101]);

        $this->artisan('ops:heartbeat', ['--force' => true])->assertFailed();

        $this->assertSame(0, $this->pings());
        $this->assertStringContainsString('disk', $this->errors()[0]);
    }

    public function test_no_url_means_no_http_call_at_all(): void
    {
        config(['jitu.ops.heartbeat_url' => '']);
        Http::fake();

        $this->artisan('ops:heartbeat', ['--force' => true])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_stays_quiet_in_testing_unless_forced(): void
    {
        Http::fake();

        $this->artisan('ops:heartbeat')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_network_error_on_the_ping_is_only_logged(): void
    {
        $attempts = 0;
        Http::fake([
            self::GATEWAY_HEALTH => Http::response(['ok' => true]),
            'uptime.betterstack.test/*' => function () use (&$attempts) {
                $attempts++;
                throw new ConnectionException('cURL error 6: Could not resolve host for '.self::URL);
            },
        ]);

        $this->artisan('ops:heartbeat', ['--force' => true])->assertSuccessful();

        // Tried twice (one retry), then a warning without the URL.
        $this->assertSame(2, $attempts);
        $warnings = collect($this->logged)->where('level', 'warning')->pluck('message')->all();
        $this->assertSame(['ops:heartbeat: ping gagal (ConnectionException)'], $warnings);
        $this->assertUrlNeverLogged();
    }

    public function test_ops_check_exits_0_when_healthy_and_1_when_a_check_fails(): void
    {
        $this->healthyGateway();
        $this->artisan('ops:check')
            ->expectsOutputToContain('Database: query berhasil')
            ->expectsOutputToContain('Gateway WhatsApp: gateway sehat di port 3100')
            ->assertExitCode(0);
        $this->assertSame(0, $this->pings(), 'ops:check never pings');

        Cache::forget(HealthChecks::QUEUE_KEY);
        $this->artisan('ops:check')
            ->expectsOutputToContain('Antrean (kanari): kanari antrean belum pernah berjalan')
            ->assertExitCode(1);
        $this->assertUrlNeverLogged();
    }

    public function test_the_queue_canary_only_records_when_it_ran_and_is_scheduled_every_minute(): void
    {
        Cache::forget(HealthChecks::QUEUE_KEY);
        $this->travelTo(now()->startOfMinute());

        (new QueueCanary)->handle();

        $this->assertSame(now()->getTimestamp(), Cache::get(HealthChecks::QUEUE_KEY));
        // No tenant data: the job carries nothing at all.
        $this->assertNull((new \ReflectionClass(QueueCanary::class))->getConstructor());

        $events = collect(app(Schedule::class)->events());
        $this->assertTrue($events->contains(fn ($e) => $e->description === 'ops-queue-canary' && $e->expression === '* * * * *'));
        $this->assertTrue($events->contains(fn ($e) => str_contains($e->command ?? '', 'ops:heartbeat') && $e->expression === '* * * * *' && $e->withoutOverlapping));
    }
}
