<?php

namespace App\Actions;

use App\Enums\Role;
use App\Media\Library;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\Industry;
use App\Support\IndustryTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Creates a tenant with its industry's starting pipeline, lead sources, lost reasons, follow-ups and AI
 * texts (config/industries.php), and makes the given user its admin.
 */
class ProvisionTenant
{
    public function __construct(private CurrentTenant $current, private IndustryTemplate $template) {}

    public function __invoke(string $name, string $slug, User $admin, ?string $industry = null): Tenant
    {
        return DB::transaction(function () use ($name, $slug, $admin, $industry) {
            $tenant = Tenant::create([
                'name' => $name,
                'slug' => $slug,
                'plan' => 'trial',
                'status' => 'trial',
                'trial_ends_at' => now()->addDays(14),
                'industry' => Industry::make($industry)->key,
            ]);

            $tenant->currentSubscription();

            $tenant->users()->attach($admin, ['role' => Role::Admin->value, 'status' => 'active']);

            $this->current->run($tenant, function () use ($tenant) {
                $this->template->fill($tenant);

                foreach (Library::FOLDERS as $position => $folder) {
                    $tenant->mediaFolders()->create(['name' => $folder, 'position' => $position + 1]);
                }
            });

            return $tenant;
        });
    }
}
