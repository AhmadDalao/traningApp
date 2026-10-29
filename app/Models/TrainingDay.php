<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingDay extends Model
{
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
