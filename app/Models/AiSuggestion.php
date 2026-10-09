<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['lead_id', 'question', 'answer', 'status'])]
class AiSuggestion extends Model
{
    use BelongsToTenant;
}
