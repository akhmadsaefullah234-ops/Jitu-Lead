<?php

namespace App\Ai;

use App\Enums\Role;
use App\Filament\Pages\Inbox;
use App\Mail\AiReplyFailed;
use App\Models\Tenant;
use App\Models\WaMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells an agency's admins by e-mail that the AI could not answer a chat (the API refused or did not answer), so a
 * person replies. At most one e-mail per agency per hour: an outage that hits many chats is one message, and the
 * Inbox shows each chat that waits.
 */
class AiFailureAlert
{
    public const EVERY_MINUTES = 60;

    public function tell(Tenant $tenant, WaMessage $inbound, string $reason): void
    {
        if (! Cache::add('ai-failure-alert:'.$tenant->getKey(), true, now()->addMinutes(self::EVERY_MINUTES))) {
            return;
        }

        $url = Inbox::getUrl(['lead' => $inbound->conversation?->lead_id], panel: 'app', tenant: $tenant);

        foreach ($tenant->activeUsers()->wherePivot('role', Role::Admin->value)->get() as $admin) {
            try {
                Mail::to($admin->email)->send(new AiReplyFailed($tenant, $reason, $url));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
