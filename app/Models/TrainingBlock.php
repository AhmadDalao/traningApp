<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingBlock extends Model
{
    protected $fillable = ['name', 'start_week', 'end_week', 'position', 'type', 'description'];

    public function trainingPlan()
    {
        return $this->belongsTo(TrainingPlan::class);
    }

    public function trainingDays()
    {
        return $this->hasMany(TrainingDay::class)->orderBy('position')->orderBy('id');
    }
}
