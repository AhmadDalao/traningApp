<?php

namespace App\Policies;

use App\Models\BodyWeightEntry;
use App\Models\User;

class BodyWeightEntryPolicy
{
    public function update(User $user, BodyWeightEntry $entry): bool
    {
        return $user->id === $entry->user_id;
    }

    public function delete(User $user, BodyWeightEntry $entry): bool
    {
        return $user->id === $entry->user_id;
    }
}
