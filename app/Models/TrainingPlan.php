<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingPlan extends Model
{
    protected $fillable = ['name', 'description'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function trainingDays()
    {
        return $this->hasMany(TrainingDay::class)->orderBy('position')->orderBy('id');
    }
}
