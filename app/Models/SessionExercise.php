<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SessionExercise extends Model
{
    protected $fillable = ['source_training_exercise_id', 'source_exercise_key', 'name', 'tracking_mode', 'planned_sets', 'planned_reps_text', 'planned_weight_kg', 'superset_group', 'thumbnail_path', 'custom_icon_path', 'icon', 'notes', 'position', 'status'];

    protected $appends = ['thumbnail_url', 'custom_icon_url'];

    protected function casts(): array
    {
        return ['planned_weight_kg' => 'decimal:2'];
    }

    public function scheduleEntry()
    {
        return $this->belongsTo(ScheduleEntry::class);
    }

    public function sets()
    {
        return $this->hasMany(SessionSet::class)->orderBy('set_number');
    }

    public function refreshStatus(): void
    {
        if ($this->status !== 'skipped') {
            $this->update(['status' => $this->sets()->exists() && ! $this->sets()->whereNull('completed_at')->exists() ? 'completed' : 'active']);
        }
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail_path ? Storage::disk('public')->url($this->thumbnail_path) : null;
    }

    public function getCustomIconUrlAttribute(): ?string
    {
        return $this->custom_icon_path ? Storage::disk('public')->url($this->custom_icon_path) : null;
    }
}
