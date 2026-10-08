<?php

namespace App\Models;

use App\Enums\StageRequirement;
use App\Enums\StageType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'position', 'type', 'requirement', 'default_action', 'default_due_hours'])]
class Stage extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'type' => StageType::class,
            'requirement' => StageRequirement::class,
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function isOpen(): bool
    {
        return $this->type === StageType::Open;
    }
}
