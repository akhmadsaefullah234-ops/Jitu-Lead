<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MembershipPolicy extends AdminOnlyPolicy
{
    public function update(User $user, Model $record): bool
    {
        return parent::update($user, $record);
    }

    /**
     * Neither the last admin nor yourself can be removed from the team.
     */
    public function delete(User $user, Model $record): bool
    {
        /** @var Membership $record */
        return parent::delete($user, $record)
            && $record->user_id !== $user->getKey()
            && ! $record->isLastActiveAdmin();
    }
}
