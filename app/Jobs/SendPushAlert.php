<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Push\PushTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends one notification to every device of the given people, and forgets devices that no longer exist. */
class SendPushAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * @param  list<int>  $userIds
     * @param  array{title: string, body: string, url: string, tag?: string}  $payload
     */
    public function __construct(public array $userIds, public array $payload) {}

    public function handle(PushTransport $transport): void
    {
        $subscriptions = PushSubscription::query()->whereIn('user_id', $this->userIds)->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        $results = $transport->send($subscriptions, $this->payload);

        $gone = array_keys(array_filter($results, fn ($r) => $r === 'gone'));
        $sent = array_keys(array_filter($results, fn ($r) => $r === 'ok'));

        PushSubscription::query()->whereIn('id', $gone)->delete();
        PushSubscription::query()->whereIn('id', $sent)->update(['last_sent_at' => now()]);
    }
}
