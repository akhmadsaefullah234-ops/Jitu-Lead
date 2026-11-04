<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\WelcomeMessage;
use App\Support\CurrentTenant;
use App\Welcome\WelcomeSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one booked WhatsApp welcome when it is due (App\Welcome\WelcomeSender). The command welcome:send
 * sweeps every minute too, so a welcome whose job was lost still goes out; whichever comes first sends it.
 */
class SendWelcomeMessage implements ShouldQueue
{
    use Queueable;

    // Waiting and retrying are booked again by WelcomeSender, never by the queue.
    public int $tries = 1;

    public function __construct(public int $welcomeId) {}

    public function handle(CurrentTenant $current, WelcomeSender $sender): void
    {
        $welcome = WelcomeMessage::query()->withoutGlobalScopes()->find($this->welcomeId);
        $tenant = $welcome ? Tenant::query()->find($welcome->tenant_id) : null;

        if ($welcome === null || $tenant === null || $welcome->status !== WelcomeMessage::PENDING || $welcome->due_at->isFuture()) {
            return;
        }

        $current->run($tenant, fn () => $sender->process($welcome, $tenant));
    }
}
