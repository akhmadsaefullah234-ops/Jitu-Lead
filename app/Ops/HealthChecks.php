<?php

namespace App\Ops;

use App\Enums\WaChannelType;
use App\Models\WaChannel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The operator's health checks (ops:heartbeat, ops:check; docs/ops-monitoring.md). Each answer says what was seen in
 * plain words, never tenant data, phone numbers, message text or the heartbeat URL.
 *
 * The scheduler itself is not checked here: ops:heartbeat runs from it, so pings that stop already mean it stopped.
 */
final class HealthChecks
{
    public const QUEUE_KEY = 'ops.queue.last_run';

    public const FAILED_JOBS_WINDOW_MINUTES = 15;

    /** @return array<string, array{status: 'ok'|'fail'|'skip', detail: string}> keyed by check name */
    public function run(): array
    {
        $results = [];

        foreach (['database', 'queue', 'gateway', 'failed_jobs', 'disk'] as $name) {
            try {
                $results[$name] = $this->{'check'.str($name)->studly()}();
            } catch (Throwable $e) {
                $results[$name] = self::fail('pemeriksaan error: '.class_basename($e));
            }
        }

        return $results;
    }

    /** @param  array<string, array{status: string, detail: string}>  $results  @return list<string> */
    public static function failed(array $results): array
    {
        return array_keys(array_filter($results, fn (array $r) => $r['status'] === 'fail'));
    }

    /** @return array{status: 'ok'|'fail'|'skip', detail: string} */
    private function checkDatabase(): array
    {
        DB::select('select 1');

        return self::ok('query berhasil');
    }

    /** @return array{status: 'ok'|'fail'|'skip', detail: string} */
    private function checkQueue(): array
    {
        $max = (int) config('jitu.ops.queue_max_age_minutes', 5);
        $last = Cache::get(self::QUEUE_KEY);

        if (! is_numeric($last)) {
            return self::fail('kanari antrean belum pernah berjalan');
        }

        $age = now()->getTimestamp() - (int) $last;
        $minutes = intdiv(max($age, 0), 60);

        return $age > $max * 60
            ? self::fail("kanari antrean terakhir berjalan {$minutes} menit lalu (batas {$max} menit)")
            : self::ok("kanari antrean berjalan {$age} detik lalu");
    }

    /** @return array{status: 'ok'|'fail'|'skip', detail: string} */
    private function checkGateway(): array
    {
        $url = config('whatsapp.gateway.url');
        if (blank($url)) {
            return self::skip('gateway platform tidak dipakai (WHATSAPP_GATEWAY_URL kosong)');
        }

        $usesQr = WaChannel::query()->withoutGlobalScopes()
            ->where('type', WaChannelType::Gateway->value)
            ->get()
            ->contains(fn (WaChannel $channel) => $channel->usesPlatformGateway());
        if (! $usesQr) {
            return self::skip('belum ada nomor scan QR');
        }

        $parts = parse_url((string) $url);
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80);

        try {
            $response = Http::timeout(3)->connectTimeout(3)->acceptJson()->get("http://127.0.0.1:{$port}/health");
        } catch (Throwable) {
            return self::fail("gateway tidak menjawab di port {$port}");
        }

        if (! $response->ok()) {
            return self::fail("gateway /health menjawab HTTP {$response->status()}");
        }

        return $response->json('ok') === true
            ? self::ok("gateway sehat di port {$port}")
            : self::fail('gateway /health tidak menjawab ok:true');
    }

    /** @return array{status: 'ok'|'fail'|'skip', detail: string} */
    private function checkFailedJobs(): array
    {
        $table = config('queue.failed.table', 'failed_jobs');
        if (! Schema::hasTable($table)) {
            return self::skip("tabel {$table} tidak ada");
        }

        $max = (int) config('jitu.ops.failed_jobs_max', 20);
        $window = self::FAILED_JOBS_WINDOW_MINUTES;
        $count = DB::table($table)->where('failed_at', '>=', now()->subMinutes($window))->count();

        return $count < $max
            ? self::ok("{$count} job gagal dalam {$window} menit (batas {$max})")
            : self::fail("{$count} job gagal dalam {$window} menit (batas {$max})");
    }

    /** @return array{status: 'ok'|'fail'|'skip', detail: string} */
    private function checkDisk(): array
    {
        $min = (float) config('jitu.ops.disk_min_percent', 10);
        $free = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());

        if ($free === false || $total === false || $total <= 0) {
            return self::fail('ruang disk tidak terbaca');
        }

        $percent = round($free / $total * 100, 1);

        return $percent >= $min
            ? self::ok("sisa disk {$percent}% (batas {$min}%)")
            : self::fail("sisa disk {$percent}% (batas {$min}%)");
    }

    /** @return array{status: 'ok', detail: string} */
    private static function ok(string $detail): array
    {
        return ['status' => 'ok', 'detail' => $detail];
    }

    /** @return array{status: 'fail', detail: string} */
    private static function fail(string $detail): array
    {
        return ['status' => 'fail', 'detail' => $detail];
    }

    /** @return array{status: 'skip', detail: string} */
    private static function skip(string $detail): array
    {
        return ['status' => 'skip', 'detail' => $detail];
    }
}
