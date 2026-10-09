<?php

namespace App\Jobs;

use App\Actions\GenerateAiReply;
use App\Models\Tenant;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAiReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $tenantId, public int $messageId)
    {
        $this->afterCommit();
    }

    public function handle(CurrentTenant $current, GenerateAiReply $generate): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $current->run($tenant, function () use ($generate) {
            $message = WaMessage::query()->with('channel')->find($this->messageId);

            if ($message !== null) {
                $generate($message);
            }
        });
    }
}
