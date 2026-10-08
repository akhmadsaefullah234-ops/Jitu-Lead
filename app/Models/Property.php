<?php

namespace App\Models;

use App\Enums\PropertyKind;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kind', 'name', 'property_type', 'location', 'developer', 'price_from', 'status', 'details'])]
class Property extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'kind' => PropertyKind::class,
            'details' => 'array',
        ];
    }
}
