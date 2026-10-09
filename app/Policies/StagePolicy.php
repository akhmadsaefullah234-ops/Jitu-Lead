<?php

namespace App\Policies;

use App\Models\Stage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A stage that still holds leads, or is the last one of its kind, stays: the
 * pipeline needs a place to start, win and lose.
 */
class StagePolicy extends AdminOnlyPolicy
{
    public function delete(User $user, Model $record): bool
    {
        /** @var Stage $record */
        return parent::delete($user, $record)
            && ! $record->leads()->withTrashed()->exists()
            && Stage::query()->where('type', $record->type)->whereKeyNot($record->getKey())->exists();
    }
}
