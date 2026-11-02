<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class TrainingDay extends Model
{
    protected $appends = ['thumbnail_url'];

    protected $hidden = ['cover_thumbnail_path'];

    public function scopeWithThumbnail(Builder $query): void
    {
        $query->addSelect(['cover_thumbnail_path' => TrainingExercise::select('thumbnail_path')
            ->whereColumn('training_day_id', 'training_days.id')->whereNotNull('thumbnail_path')->where('thumbnail_path', '!=', '')
            ->orderBy('position')->orderBy('id')->limit(1)]);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if ($this->type === 'rest') {
            return null;
        }
        $path = $this->cover_thumbnail_path ?? ($this->relationLoaded('exercises') ? $this->exercises->first(fn ($exercise) => filled($exercise->thumbnail_path))?->thumbnail_path : null);

        return $path ? Storage::disk('public')->url($path) : null;
    }

    protected $fillable = ['icon', 'title', 'type', 'focus_area', 'notes', 'position', 'weekdays', 'default_time', 'training_block_id'];

    protected function casts(): array
    {
        return ['weekdays' => 'array', 'schedule_rules' => 'array'];
    }

    public function weekdaysOn(string $date): array
    {
        $weekdays = $this->weekdays ?? [];
        foreach ($this->schedule_rules ?? [] as $rule) {
            if ($rule['from'] === null || $rule['from'] <= $date) {
                $weekdays = $rule['weekdays'];
            }
        }

        return array_map('intval', $weekdays);
    }

    public function trainingBlock()
    {
        return $this->belongsTo(TrainingBlock::class);
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
