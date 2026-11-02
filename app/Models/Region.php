<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One administrative region of Indonesia by its Kemendagri code: a province ("32", level 1), a city or regency
 * ("32.04", level 2), a district ("32.04.05", level 3) or a village ("32.04.05.2001", level 4, with its postal
 * code when known). Shared by every agency; filled by "php artisan regions:import".
 */
class Region extends Model
{
    public const PROVINCE = 1;

    public const CITY = 2;

    public const DISTRICT = 3;

    public const VILLAGE = 4;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
