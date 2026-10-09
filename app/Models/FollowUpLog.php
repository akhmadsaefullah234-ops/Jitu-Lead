<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['follow_up_rule_id', 'lead_id', 'stage_id', 'status', 'note'])]
class FollowUpLog extends Model
{
    use BelongsToTenant;
}
