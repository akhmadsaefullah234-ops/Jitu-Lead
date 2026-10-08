<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'plan', 'status', 'timezone', 'trial_ends_at'])]
class Tenant extends Model
{
    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function activeUsers(): BelongsToMany
    {
        return $this->users()->wherePivot('status', 'active');
    }

    public function agents(): BelongsToMany
    {
        return $this->activeUsers()->wherePivot('role', Role::Agent->value);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('position');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function leadSources(): HasMany
    {
        return $this->hasMany(LeadSource::class);
    }

    public function lostReasons(): HasMany
    {
        return $this->hasMany(LostReason::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
