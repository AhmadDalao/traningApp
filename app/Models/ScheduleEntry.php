<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ScheduleEntry extends Model
{
    protected $fillable = ['icon', 'training_day_id', 'title', 'entry_type', 'focus_area', 'scheduled_date', 'scheduled_time', 'status', 'notes', 'completed_at', 'workout_snapshot', 'plan_occurrence_key'];

    protected function casts(): array
    {
        return ['scheduled_date' => 'date:Y-m-d', 'completed_at' => 'datetime', 'workout_snapshot' => 'array'];
    }

    public function scopePast(Builder $query): void
    {
        $now = now();
        $query->where(function (Builder $query) use ($now) {
            $query->where('scheduled_date', '<', $now->toDateString())
                ->orWhere(fn (Builder $today) => $today->where('scheduled_date', $now->toDateString())
                    ->where(fn (Builder $time) => $time->whereNull('scheduled_time')->orWhere('scheduled_time', '<=', $now->format('H:i:s'))));
        });
    }

    public function captureWorkoutSnapshot(array &$copiedPaths): array
    {
        $this->load('trainingDay.exercises', 'trainingDay.trainingPlan');
        $day = $this->trainingDay;
        $exercises = [];
        foreach ($this->entry_type === 'training' ? ($day?->exercises ?? []) : [] as $exercise) {
            $media = ['thumbnail_path' => null, 'custom_icon_path' => null];
            foreach ($media as $field => $unused) {
                if ($exercise->$field && Storage::disk('public')->exists($exercise->$field)) {
                    $copy = 'workout-thumbnails/'.Str::uuid().'.'.pathinfo($exercise->$field, PATHINFO_EXTENSION);
                    $copiedPaths[] = $copy;
                    if (! Storage::disk('public')->copy($exercise->$field, $copy)) {
                        throw new \RuntimeException('Could not preserve the exercise image. Please try again.');
                    }
                    $media[$field] = $copy;
                }
            }
            $exercises[] = [
                ...$exercise->only('id', 'name', 'sets', 'reps', 'target_weight', 'superset_group', 'notes', 'position', 'icon'),
                'actual_weight' => null, ...$media,
            ];
        }

        return [
            'version' => 1, 'title' => $this->title, 'focus_area' => $this->focus_area, 'icon' => $this->icon,
            'training_day_title' => $day?->title, 'training_plan_id' => $day?->training_plan_id,
            'training_plan_name' => $day?->trainingPlan?->name, 'template_available' => (bool) $day,
            'exercises' => $exercises,
        ];
    }

    public function workoutDetails(): ?array
    {
        if ($this->workout_snapshot !== null) {
            $snapshot = $this->workout_snapshot;
            $snapshot['exercises'] = array_map(fn ($exercise) => [
                ...$exercise,
                'thumbnail_url' => ! empty($exercise['thumbnail_path']) ? Storage::disk('public')->url($exercise['thumbnail_path']) : null,
                'custom_icon_url' => ! empty($exercise['custom_icon_path']) ? Storage::disk('public')->url($exercise['custom_icon_path']) : null,
            ], $snapshot['exercises'] ?? []);

            return $snapshot;
        }
        // Legacy completions have no reliable historical exercise record to reconstruct.
        if ($this->status === 'completed' || ! $this->trainingDay) {
            return null;
        }

        return ['training_plan_name' => $this->trainingDay->trainingPlan->name, 'training_day_title' => $this->trainingDay->title,
            'exercises' => $this->entry_type === 'training' ? $this->trainingDay->exercises->toArray() : []];
    }

    public function workoutExerciseCount(): int
    {
        return $this->entry_type === 'rest' ? 0 : ($this->workout_snapshot !== null
            ? count($this->workout_snapshot['exercises'] ?? [])
            : ($this->status === 'completed' ? 0 : ($this->trainingDay?->exercises_count ?? 0)));
    }

    public function snapshotThumbnailPaths(): array
    {
        return collect($this->workout_snapshot['exercises'] ?? [])->flatMap(fn ($exercise) => [$exercise['thumbnail_path'] ?? null, $exercise['custom_icon_path'] ?? null])->filter()->values()->all();
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
