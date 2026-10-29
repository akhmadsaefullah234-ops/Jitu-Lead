<?php

namespace App\Models;

use App\Enums\PropertyKind;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kind', 'name', 'property_type', 'location', 'developer', 'price_from', 'status', 'details', 'weight_grams', 'length_cm', 'width_cm', 'height_cm'])]
class Property extends Model
{
    use BelongsToTenant;

    // A shop's products have no kind or status picker, so they start as these
    protected $attributes = ['kind' => 'primary', 'status' => 'available'];

    protected function casts(): array
    {
        return [
            'kind' => PropertyKind::class,
            'details' => 'array',
        ];
    }
}
