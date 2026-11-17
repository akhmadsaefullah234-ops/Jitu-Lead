<?php

namespace App\Jobs;

use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/** "Uji AI saya": the ten test questions, in their own process (the evaluation holds back jobs, mail and HTTP). */
class RunAiCheck implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $tenantId) {}

    public static function runningKey(int $tenantId): string
    {
        return 'ai-check-running:'.$tenantId;
    }

    public function handle(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        try {
            if ($tenant !== null) {
                Process::timeout(840)->run([PHP_BINARY, base_path('artisan'), 'ai:evaluate', '--tenant='.$tenant->slug, '--quick', '--record=quick']);
            }
        } finally {
            Cache::forget(self::runningKey($this->tenantId));
        }
    }

    public function failed(): void
    {
        Cache::forget(self::runningKey($this->tenantId));
    }
}
