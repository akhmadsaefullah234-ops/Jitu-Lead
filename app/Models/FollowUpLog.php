<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['follow_up_rule_id', 'lead_id', 'stage_id', 'status', 'note', 'product_ids'])]
class FollowUpLog extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        // product_ids: the products a "Tanya produk" message listed, in its order
        return ['product_ids' => 'array'];
    }
}
