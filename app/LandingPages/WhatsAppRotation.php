<?php

namespace App\LandingPages;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * "Agen bergiliran" on WhatsApp buttons: each click goes to the next agent who takes leads and has a WhatsApp
 * number, in turn. Without such agents it falls back to the agency's own number. Must run inside the tenant.
 */
class WhatsAppRotation
{
    /** Digits of the next number (62...), or '' when the agency has no number at all. */
    public static function next(Tenant $tenant): string
    {
        $numbers = $tenant->leadRecipients()
            ->map(fn (User $u) => PageData::digits($u->whatsapp))
            ->filter()->unique()->values();

        if ($numbers->isEmpty()) {
            return PageData::agencyNumber();
        }

        $key = 'wa-rotation:'.$tenant->getKey();
        Cache::add($key, 0, now()->addDays(30));
        $turn = (int) Cache::increment($key);

        return $numbers[($turn - 1) % $numbers->count()];
    }

    /** The WhatsApp number of the agent a lead was given to, or ''. */
    public static function ownerOf(?User $owner): string
    {
        return PageData::digits($owner?->whatsapp);
    }
}
