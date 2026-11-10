<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An agency's own mapping of one of our places (postal code, kelurahan, kecamatan, kota; region_key) to the
 * shipping provider's place, filled from an agent's choice and reused (App\Shipping\Shipments\ProviderLocations).
 */
#[Fillable(['provider', 'region_key', 'postal_code', 'kelurahan', 'kecamatan', 'kota', 'location_id', 'location_label', 'location_postal', 'location', 'used_count', 'user_id'])]
class ProviderLocationCorrection extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['location' => 'array', 'used_count' => 'integer'];
    }
}
