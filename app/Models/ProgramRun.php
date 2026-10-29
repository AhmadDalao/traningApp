<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgramRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paused_from' => 'date:Y-m-d', 'resume_on' => 'date:Y-m-d', 'ended_at' => 'datetime', 'ended_position' => 'array', 'schedule_shifts' => 'array', 'started_on' => 'date:Y-m-d', 'completed_at' => 'datetime', 'definition_snapshot' => 'array'];
    }

    public function holds(ScheduleEntry $entry): bool
    {
        return $this->status === 'ended' || ($this->status === 'paused' && $this->paused_from && $entry->scheduled_date->gte($this->paused_from));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function trainingPlan()
    {
        return $this->belongsTo(TrainingPlan::class);
    }

    public function sessions()
    {
        return $this->hasMany(ScheduleEntry::class)->orderBy('program_week')->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('id');
    }
}
