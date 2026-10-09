<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'question', 'content', 'source', 'active'])]
class AiKnowledgeItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
