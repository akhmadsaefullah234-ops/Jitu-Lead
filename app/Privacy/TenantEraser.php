<?php

namespace App\Privacy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes an agency and everything it owns. The database removes the agency's
 * rows itself (every table cascades from the tenant); this also removes the
 * landing page images, and the accounts of members who belonged to no other
 * agency. The acting admin's own account and any super admin stay.
 */
class TenantEraser
{
    /** @return array{members_removed: int} */
    public function __invoke(Tenant $tenant, User $actor): array
    {
        $id = $tenant->getKey();

        $removed = DB::transaction(function () use ($tenant, $actor, $id) {
            $orphans = User::query()
                ->whereIn('id', DB::table('tenant_user')->where('tenant_id', $id)->pluck('user_id'))
                ->whereKeyNot($actor->getKey())->where('is_super_admin', false)
                ->whereNotIn('id', DB::table('tenant_user')->where('tenant_id', '!=', $id)->select('user_id'))
                ->pluck('id');

            $tenant->delete();
            User::query()->whereIn('id', $orphans)->delete();

            return $orphans->count();
        });

        Storage::disk('public')->deleteDirectory("landing/$id");
        Log::info('Agensi dihapus oleh adminnya', ['tenant_id' => $id, 'actor_id' => $actor->getKey(), 'members_removed' => $removed]);

        return ['members_removed' => $removed];
    }
}
