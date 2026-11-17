<?php

namespace App\Jobs;

use App\Actions\GenerateAiReply;
use App\Ai\ConversationMemory;
use App\Models\Tenant;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateAiReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $tenantId, public int $messageId, public bool $afterHours = false)
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

            if ($message !== null && $generate($message, $this->afterHours) !== null && $message->conversation !== null && ConversationMemory::due($message->conversation)) {
                // The summary of a long chat is kept up to date after the reply, so the next answer remembers it.
                UpdateConversationMemory::dispatch($this->tenantId, $message->conversation_id);
            }
        });
    }

    /** A timeout or crash outside the AI call: the chat still goes to an agent, and the admins get the e-mail. */
    public function failed(?Throwable $e): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        app(CurrentTenant::class)->run($tenant, function () use ($e) {
            $message = WaMessage::query()->find($this->messageId);

            if ($message !== null) {
                app(GenerateAiReply::class)->failed($message, $e ?? new \RuntimeException('job gagal'));
            }
        });
    }
}
