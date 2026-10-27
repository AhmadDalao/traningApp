<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class TrainingExercise extends Model
{
    protected $fillable = ['icon', 'name', 'sets', 'reps', 'target_weight', 'superset_group', 'thumbnail_path', 'notes', 'position', 'library_key', 'custom_icon_path', 'tracking_mode'];

    protected $appends = ['thumbnail_url', 'custom_icon_url'];

    protected function casts(): array
    {
        return ['target_weight' => 'decimal:2'];
    }

    public function trainingDay()
    {
        return $this->belongsTo(TrainingDay::class);
    }

    public function getThumbnailUrlAttribute()
    {
        return $this->thumbnail_path ? Storage::disk('public')->url($this->thumbnail_path) : null;
    }

    public function getCustomIconUrlAttribute()
    {
        return $this->custom_icon_path ? Storage::disk('public')->url($this->custom_icon_path) : null;
    }
}
