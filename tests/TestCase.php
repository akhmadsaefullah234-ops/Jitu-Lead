<?php

namespace Tests;

use App\Actions\ProvisionTenant;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function makeTenant(string $slug = 'griya-prima', ?User $admin = null): Tenant
    {
        $admin ??= User::factory()->create();

        return app(ProvisionTenant::class)(ucwords(str_replace('-', ' ', $slug)), $slug, $admin);
    }

    protected function member(Tenant $tenant, Role $role): User
    {
        $user = User::factory()->create();
        $tenant->users()->attach($user, ['role' => $role->value, 'status' => 'active']);

        return $user;
    }

    protected function adminOf(Tenant $tenant): User
    {
        return $tenant->users()->wherePivot('role', Role::Admin->value)->firstOrFail();
    }

    /**
     * Act as a user inside a tenant, the way a panel request would.
     */
    protected function actingInTenant(User $user, Tenant $tenant): static
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tenant);
        app(CurrentTenant::class)->set($tenant);

        return $this;
    }

    protected function makeLead(Tenant $tenant, array $attributes = []): Lead
    {
        return app(CurrentTenant::class)->run($tenant, fn () => Lead::create($attributes + [
            'name' => 'Calon Pembeli',
            'phone' => '0812'.random_int(10000000, 99999999),
            'stage_id' => $this->stage($tenant, 'Lead baru')->getKey(),
            'owner_id' => $this->adminOf($tenant)->getKey(),
            'next_action' => 'Hubungi lewat WhatsApp atau telepon',
            'next_action_due_at' => now()->addHour(),
        ]));
    }

    protected function stage(Tenant $tenant, string $name): Stage
    {
        return Stage::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->where('name', $name)->firstOrFail();
    }
}
