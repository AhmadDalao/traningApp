<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleEntry extends Model
{
    protected $fillable = ['training_day_id', 'title', 'entry_type', 'focus_area', 'scheduled_date', 'status', 'notes'];

    protected function casts(): array
    {
        return ['scheduled_date' => 'date:Y-m-d'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function trainingDay()
    {
        return $this->belongsTo(TrainingDay::class);
    }
}
