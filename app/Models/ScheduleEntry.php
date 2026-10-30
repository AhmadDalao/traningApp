<?php

namespace App\Models;

use App\Services\ProgramLifecycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScheduleEntry extends Model
{
    protected $fillable = ['scheduled_by_user_id', 'icon', 'training_day_id', 'title', 'entry_type', 'focus_area', 'scheduled_date', 'scheduled_time', 'status', 'notes', 'completed_at', 'workout_snapshot', 'plan_occurrence_key', 'schedule_managed', 'duration_minutes', 'calories_burned', 'calories_source', 'estimated_calories', 'intensity', 'started_at', 'program_run_id', 'program_week', 'program_context', 'program_prescription'];

    protected function casts(): array
    {
        return ['program_context' => 'array', 'program_prescription' => 'array', 'schedule_managed' => 'boolean', 'scheduled_date' => 'date:Y-m-d', 'completed_at' => 'datetime', 'workout_snapshot' => 'array', 'started_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saved(function (self $entry) {
            if ($entry->program_run_id && ! $entry->wasRecentlyCreated && $entry->wasChanged(['status', 'started_at'])) {
                $runId = $entry->program_run_id;
                DB::afterCommit(fn () => app(ProgramLifecycle::class)->sync($runId));
            }
        });
    }

    public function lockRunForMutation(): ?ProgramRun
    {
        if (! $this->program_run_id) {
            return null;
        }
        User::whereKey($this->user_id)->lockForUpdate()->firstOrFail();

        return ProgramRun::whereKey($this->program_run_id)->lockForUpdate()->first();
    }

    public function ensureProgramAvailable(?ProgramRun $run): void
    {
        if ($run && $run->holds($this)) {
            throw ValidationException::withMessages(['program' => $run->status === 'paused'
                ? 'Resume the program before changing this workout.' : 'This program ended. Its retained workouts are read-only.']);
        }
    }

    public function scopeAvailableOnSchedule(Builder $query): void
    {
        $query->where(fn ($q) => $q->where('status', '!=', 'planned')->orWhereNull('program_run_id')
            ->orWhereHas('programRun', fn ($run) => $run->whereNotIn('status', ['paused', 'ended'])
                ->orWhere(fn ($paused) => $paused->where('status', 'paused')->whereColumn('schedule_entries.scheduled_date', '<', 'program_runs.paused_from'))));
    }

    public function programRun()
    {
        return $this->belongsTo(ProgramRun::class);
    }

    public function scopeActiveWorkout(Builder $query): void
    {
        $query->where('entry_type', 'training')->where('status', 'planned')->whereNotNull('started_at');
    }

    public function isActiveWorkout(): bool
    {
        return $this->entry_type === 'training' && $this->status === 'planned' && $this->started_at !== null;
    }

    public function scopeUpcoming(Builder $query): void
    {
        $query->availableOnSchedule()->where('status', 'planned')->where(function (Builder $dates) {
            $dates->whereDate('scheduled_date', '>', today()->toDateString())
                ->orWhere(fn (Builder $today) => $today->whereDate('scheduled_date', today()->toDateString())
                    ->where(fn (Builder $time) => $time->whereNull('scheduled_time')->orWhere('scheduled_time', '>=', now()->format('H:i:s'))));
        });
    }

    public function sessionExercises()
    {
        return $this->hasMany(SessionExercise::class)->orderBy('position');
    }

    public function scopePast(Builder $query): void
    {
        $now = now();
        $query->where(function (Builder $query) use ($now) {
            $query->whereDate('scheduled_date', '<', $now->toDateString())
                ->orWhere(fn (Builder $today) => $today->whereDate('scheduled_date', $now->toDateString())
                    ->where(fn (Builder $time) => $time->whereNull('scheduled_time')->orWhere('scheduled_time', '<=', $now->format('H:i:s'))));
        });
    }

    public function scopeHistoryVisible(Builder $query): void
    {
        // Programs can be completed out of order. Their saved Progress links must
        // remain readable before the original scheduled date, including after source deletion.
        $query->where(fn (Builder $history) => $history->past()
            ->orWhere(fn (Builder $program) => $program->whereNotNull('program_run_id')->where('status', 'completed')));
    }

    public function captureWorkoutSnapshot(array &$copiedPaths): array
    {
        if ($this->program_prescription !== null) {
            $snapshot = $this->program_prescription;
            foreach ($snapshot['exercises'] as &$exercise) {
                foreach (['thumbnail_path', 'custom_icon_path'] as $field) {
                    if (! empty($exercise[$field]) && Storage::disk('public')->exists($exercise[$field])) {
                        $copy = 'workout-thumbnails/'.Str::uuid().'.'.pathinfo($exercise[$field], PATHINFO_EXTENSION);
                        $copiedPaths[] = $copy;
                        if (! Storage::disk('public')->copy($exercise[$field], $copy)) {
                            throw new \RuntimeException('Could not preserve the exercise image.');
                        }
                        $exercise[$field] = $copy;
                    }
                }
            }

            return $snapshot;
        }
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
                'source_exercise_key' => $exercise->library_key, 'tracking_mode' => $exercise->tracking_mode ?? 'reps',
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
        if ($this->workout_snapshot !== null || $this->program_prescription !== null) {
            $snapshot = $this->workout_snapshot ?? $this->program_prescription;
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
            : ($this->program_prescription !== null ? count($this->program_prescription['exercises'] ?? []) : ($this->status === 'completed' ? 0 : ($this->trainingDay?->exercises_count ?? 0))));
    }

    public function snapshotThumbnailPaths(): array
    {
        return collect($this->workout_snapshot['exercises'] ?? [])->flatMap(fn ($exercise) => [$exercise['thumbnail_path'] ?? null, $exercise['custom_icon_path'] ?? null])->filter()->values()->all();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scheduledBy()
    {
        return $this->belongsTo(User::class, 'scheduled_by_user_id');
    }

    public function trainingDay()
    {
        return $this->belongsTo(TrainingDay::class);
    }
}
