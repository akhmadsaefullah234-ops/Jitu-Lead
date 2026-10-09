<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The platform owner's own wording of a legal page, and whether a lawyer has reviewed it. Not tenant-owned. */
class LegalDocument extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }
}
