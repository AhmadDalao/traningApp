<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingDay extends Model
{
    protected $fillable = ['icon', 'title', 'type', 'focus_area', 'notes', 'position', 'weekdays', 'default_time'];

    protected function casts(): array
    {
        return ['weekdays' => 'array'];
    }

    public function trainingPlan()
    {
        return $this->belongsTo(TrainingPlan::class);
    }

    public function exercises()
    {
        return $this->hasMany(TrainingExercise::class)->orderBy('position')->orderBy('id');
    }

    public function scheduleEntries()
    {
        return $this->hasMany(ScheduleEntry::class);
    }
}
