<?php

namespace App\Console\Commands;

use App\Ops\HealthChecks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dead-man's switch for the operator (docs/ops-monitoring.md): runs every minute from the scheduler and pings the
 * heartbeat URL only when every health check passes. When the pings stop (a check fails, or the scheduler, the
 * server or its network is down), the outside monitor emails the operator.
 *
 * Nothing here ever throws on a network error, and the URL never reaches a log or the console.
 */
#[Signature('ops:heartbeat {--force : juga di APP_ENV local/testing}')]
#[Description('Kirim ping heartbeat ke pemantau operator bila database, antrean, gateway, failed_jobs, dan disk sehat')]
class OpsHeartbeatCommand extends Command
{
    public function handle(HealthChecks $checks): int
    {
        $url = config('jitu.ops.heartbeat_url');
        if (blank($url)) {
            return self::SUCCESS;
        }

        if (app()->environment('local', 'testing') && ! $this->option('force')) {
            return self::SUCCESS;
        }

        $results = $checks->run();
        $failed = HealthChecks::failed($results);

        if ($failed !== []) {
            $details = collect($failed)->map(fn (string $name) => $name.' ('.$results[$name]['detail'].')')->implode('; ');
            Log::error('ops:heartbeat: tidak ada ping, pemeriksaan gagal: '.$details, ['failed' => $failed]);

            return self::FAILURE;
        }

        $this->ping((string) $url);

        return self::SUCCESS;
    }

    private function ping(string $url): void
    {
        try {
            $response = Http::timeout(5)->connectTimeout(5)->retry(2, 1000, throw: false)->get($url);

            if (! $response->successful()) {
                Log::warning('ops:heartbeat: pemantau menjawab HTTP '.$response->status());
            }
        } catch (Throwable $e) {
            // The exception message holds the URL (a secret): only its type is logged.
            Log::warning('ops:heartbeat: ping gagal ('.class_basename($e).')');
        }
    }
}
