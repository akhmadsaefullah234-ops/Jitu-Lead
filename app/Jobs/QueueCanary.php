<?php

namespace App\Jobs;

use App\Ops\HealthChecks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Proof that the queue worker runs: queued every minute by the scheduler (routes/console.php), it only writes the
 * time it ran to the cache. ops:heartbeat counts the queue as stuck when that time gets too old. No tenant data.
 */
class QueueCanary implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Cache::put(HealthChecks::QUEUE_KEY, now()->getTimestamp(), now()->addDay());
    }
}
