<?php

namespace App\Policies;

use App\Models\ScheduleEntry;
use App\Models\User;

class ScheduleEntryPolicy
{
    public function view(User $user, ScheduleEntry $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function update(User $user, ScheduleEntry $model): bool
    {
        return $this->view($user, $model);
    }

    public function delete(User $user, ScheduleEntry $model): bool
    {
        return $this->view($user, $model);
    }
}
