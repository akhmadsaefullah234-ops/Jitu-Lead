<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Relations\Pivot;

class Membership extends Pivot
{
    protected $table = 'tenant_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'role' => Role::class,
        ];
    }
}
