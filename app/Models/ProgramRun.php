<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgramRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_on' => 'date:Y-m-d', 'completed_at' => 'datetime', 'definition_snapshot' => 'array'];
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
