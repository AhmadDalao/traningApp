<?php

namespace App\Services;

use App\Models\ScheduleEntry;
use App\Models\SessionExercise;
use App\Models\TrainingExercise;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ActiveWorkout
{
    public function start(ScheduleEntry $entry): ScheduleEntry
    {
        $paths = [];
        try {
            return DB::transaction(function () use ($entry, &$paths) {
                // Serialize starts across different sessions belonging to the same user.
                User::whereKey($entry->user_id)->lockForUpdate()->firstOrFail();
                $run = $entry->lockRunForMutation();
                $entry = ScheduleEntry::lockForUpdate()->findOrFail($entry->id);
                $entry->ensureProgramAvailable($run);
                if ($entry->entry_type !== 'training' || $entry->status !== 'planned') {
                    throw ValidationException::withMessages(['workout' => 'Only a planned training session can be started.']);
                }
                $other = ScheduleEntry::where('user_id', $entry->user_id)->activeWorkout()->whereKeyNot($entry->id)->first();
                if ($other) {
                    return $other;
                }
                if (! $entry->sessionExercises()->exists()) {
                    // Freeze media and prescription at start, before a template can change.
                    $entry->workout_snapshot ??= $entry->captureWorkoutSnapshot($paths);
                    $sourceIds = TrainingExercise::where('training_day_id', $entry->training_day_id)->pluck('id')->all();
                    foreach ($entry->workout_snapshot['exercises'] ?? [] as $position => $source) {
                        $exercise = $entry->sessionExercises()->create([
                            'source_training_exercise_id' => in_array($source['id'] ?? null, $sourceIds, true) ? $source['id'] : null,
                            'source_exercise_key' => $source['source_exercise_key'] ?? null,
                            'name' => $source['name'], 'tracking_mode' => $source['tracking_mode'] ?? 'reps',
                            'planned_sets' => $source['sets'], 'planned_reps_text' => $source['reps'] ?? null,
                            'planned_weight_kg' => $source['target_weight'] ?? null,
                            'superset_group' => $source['superset_group'] ?? null,
                            'thumbnail_path' => $source['thumbnail_path'] ?? null, 'custom_icon_path' => $source['custom_icon_path'] ?? null,
                            'icon' => $source['icon'] ?? null, 'notes' => $source['notes'] ?? null, 'position' => $position,
                        ]);
                        for ($number = 1; $number <= $exercise->planned_sets; $number++) {
                            $this->addSet($exercise, $number);
                        }
                    }
                }
                $entry->started_at ??= now();
                $entry->save();

                return $entry;
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($paths);
            throw $error;
        }
    }

    public function change(ScheduleEntry $entry, Closure $action): mixed
    {
        // Every logging/finish/discard action takes the same parent lock.
        return DB::transaction(function () use ($entry, $action) {
            $run = $entry->lockRunForMutation();
            $entry = ScheduleEntry::lockForUpdate()->findOrFail($entry->id);
            $entry->ensureProgramAvailable($run);
            if (! $entry->isActiveWorkout()) {
                throw ValidationException::withMessages(['workout' => 'Resume an active workout before changing its set logs.']);
            }

            return $action($entry);
        });
    }

    public function addSet(SessionExercise $exercise, ?int $number = null): void
    {
        $exercise->sets()->create([
            'set_number' => $number ?? ($exercise->sets()->max('set_number') ?? 0) + 1,
            'planned_reps_text' => $exercise->planned_reps_text, 'planned_weight_kg' => $exercise->planned_weight_kg,
        ]);
    }

    public function finish(ScheduleEntry $entry, bool $confirmed): void
    {
        $this->change($entry, function (ScheduleEntry $entry) use ($confirmed) {
            $entry->load('sessionExercises.sets');
            $incomplete = $entry->sessionExercises->where('status', '!=', 'skipped')
                ->sum(fn ($exercise) => $exercise->sets->whereNull('completed_at')->count());
            if ($incomplete && ! $confirmed) {
                throw ValidationException::withMessages(['incomplete_sets' => "$incomplete sets are still incomplete. Finish anyway?"]);
            }
            $entry->sessionExercises->each->refreshStatus();
            $entry->status = 'completed';
            $entry->completed_at = now();
            // Nearest whole minute, minimum one. Longer abandoned workouts remain accurate;
            // the existing calorie estimator declines durations over its 24-hour limit.
            $entry->duration_minutes = max(1, (int) round($entry->started_at->diffInMinutes($entry->completed_at)));
            if ($entry->duration_minutes > 65535) {
                throw ValidationException::withMessages(['workout' => 'This workout has been open for more than 45 days. Discard it, then use quick completion and record its actual duration.']);
            }
            $entry->intensity ??= 'moderate';
            $entry->estimated_calories = app(WorkoutCalorieEstimator::class)->forSession($entry);
            $entry->save();
        });
    }
}
