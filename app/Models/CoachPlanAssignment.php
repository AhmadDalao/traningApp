<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachPlanAssignment extends Model
{
    protected $fillable = ['coach_trainee_relationship_id', 'coach_subscription_id', 'source_training_plan_id', 'assigned_training_plan_id', 'assigned_at', 'status', 'ended_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function relationship()
    {
        return $this->belongsTo(CoachTraineeRelationship::class, 'coach_trainee_relationship_id');
    }

    public function plan()
    {
        return $this->belongsTo(TrainingPlan::class, 'assigned_training_plan_id');
    }

    public function source()
    {
        return $this->belongsTo(TrainingPlan::class, 'source_training_plan_id');
    }
}
