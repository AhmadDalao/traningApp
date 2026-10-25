<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class TrainingExercise extends Model
{
    protected $fillable = ['name', 'sets', 'reps', 'superset_group', 'thumbnail_path', 'notes', 'position'];

    protected $appends = ['thumbnail_url'];

    public function trainingDay()
    {
        return $this->belongsTo(TrainingDay::class);
    }

    public function getThumbnailUrlAttribute()
    {
        return $this->thumbnail_path ? Storage::disk('public')->url($this->thumbnail_path) : null;
    }
}
