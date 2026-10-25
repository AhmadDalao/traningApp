<?php

namespace App\Policies;

use App\Models\TrainingPlan;
use App\Models\User;

class TrainingPlanPolicy
{
    public function view(User $user, TrainingPlan $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function update(User $user, TrainingPlan $model): bool
    {
        return $this->view($user, $model);
    }

    public function delete(User $user, TrainingPlan $model): bool
    {
        return $this->view($user, $model);
    }
}
