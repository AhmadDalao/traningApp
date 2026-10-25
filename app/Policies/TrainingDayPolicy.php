<?php

namespace App\Policies;

use App\Models\TrainingDay;
use App\Models\User;

class TrainingDayPolicy
{
    public function view(User $user, TrainingDay $model): bool
    {
        return $user->id === $model->trainingPlan->user_id;
    }

    public function update(User $user, TrainingDay $model): bool
    {
        return $this->view($user, $model);
    }

    public function delete(User $user, TrainingDay $model): bool
    {
        return $this->view($user, $model);
    }
}
