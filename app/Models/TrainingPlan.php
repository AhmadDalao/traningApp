<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class TrainingPlan extends Model
{
    protected $appends = ['thumbnail_url'];

    protected $hidden = ['cover_thumbnail_path'];

    public function scopeWithThumbnail(Builder $query): void
    {
        $query->addSelect(['cover_thumbnail_path' => TrainingExercise::select('thumbnail_path')
            ->join('training_days', 'training_days.id', '=', 'training_exercises.training_day_id')
            ->whereColumn('training_days.training_plan_id', 'training_plans.id')->where('training_days.type', 'training')
            ->whereNotNull('thumbnail_path')->where('thumbnail_path', '!=', '')
            ->orderBy('training_days.position')->orderBy('training_days.id')
            ->orderBy('training_exercises.position')->orderBy('training_exercises.id')->limit(1)]);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->cover_thumbnail_path ? Storage::disk('public')->url($this->cover_thumbnail_path) : null;
    }

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
