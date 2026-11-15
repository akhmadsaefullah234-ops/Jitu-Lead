<?php

namespace App\Jobs;

use App\Ai\ConversationMemory;
use App\Models\Tenant;
use App\Models\WaConversation;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Writes a chat's summary again (ConversationMemory) after an AI reply, once enough was said since the last one. */
class UpdateConversationMemory implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /** One update per chat at a time; a second one queued meanwhile would only repeat it. */
    public int $uniqueFor = 300;

    public function __construct(public int $tenantId, public int $conversationId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->conversationId;
    }

    public function handle(CurrentTenant $current, ConversationMemory $memory): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $current->run($tenant, function () use ($memory) {
            $conversation = WaConversation::query()->find($this->conversationId);

            if ($conversation !== null && ConversationMemory::due($conversation)) {
                $memory->update($conversation);
            }
        });
    }
}
