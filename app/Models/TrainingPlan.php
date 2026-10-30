<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingPlan extends Model
{
    protected $fillable = ['name', 'description', 'start_date', 'end_date', 'schedule_mode', 'structure_type', 'duration_weeks'];

    public function isProgram(): bool
    {
        return $this->structure_type === 'program';
    }

    public function blocks()
    {
        return $this->hasMany(TrainingBlock::class)->orderBy('position')->orderBy('id');
    }

    public function programRuns()
    {
        return $this->hasMany(ProgramRun::class)->latest('id');
    }

    public function templateAssignments()
    {
        return $this->hasMany(CoachPlanAssignment::class, 'source_training_plan_id');
    }

    public function assignment()
    {
        return $this->hasOne(CoachPlanAssignment::class, 'assigned_training_plan_id');
    }

    protected function casts(): array
    {
        return ['schedule_enabled' => 'boolean', 'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function trainingDays()
    {
        return $this->hasMany(TrainingDay::class)->orderBy('position')->orderBy('id');
    }
}
