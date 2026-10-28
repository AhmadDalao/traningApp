<?php

namespace App\Services;

use App\Models\TrainingDay;
use App\Models\TrainingPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TrainingPlanDuplicator
{
    public function __construct(private ExerciseLibrary $library) {}

    public function plan(User $user, TrainingPlan $source): TrainingPlan
    {
        Gate::forUser($user)->authorize('update', $source);

        return $this->copy($user, function (&$paths) use ($user, $source) {
            $source = TrainingPlan::where('user_id', $user->id)->lockForUpdate()->findOrFail($source->id);
            $copy = $user->trainingPlans()->create([
                ...$source->only('description', 'start_date', 'end_date', 'schedule_mode'),
                'name' => $this->name($source->name, $user->trainingPlans(), 'name'),
            ]);
            if ($source->workspace_type === 'coach_template') {
                $copy->forceFill(['workspace_type' => 'coach_template'])->save();
            }
            foreach ($source->trainingDays()->lockForUpdate()->get() as $day) {
                $this->dayCopy($day, $copy, $day->title, $day->position, $paths);
            }

            return $copy;
        });
    }

    public function assign(User $coach, User $trainee, TrainingPlan $source, array $dates): TrainingPlan
    {
        $paths = [];
        try {
            return app(CoachAccess::class)->transaction($coach, $trainee, function ($relationship, $subscription) use ($coach, $trainee, $source, $dates, &$paths) {
                $source = TrainingPlan::where('user_id', $coach->id)->where('workspace_type', 'coach_template')->lockForUpdate()->findOrFail($source->id);
                $copy = $trainee->trainingPlans()->make([...$source->only('name', 'description'), ...$dates]);
                $copy->workspace_type = 'coach_assigned';
                $copy->save();
                foreach ($source->trainingDays()->lockForUpdate()->get() as $day) {
                    $this->dayCopy($day, $copy, $day->title, $day->position, $paths);
                }
                $relationship->assignments()->create(['coach_subscription_id' => $subscription->id, 'source_training_plan_id' => $source->id, 'assigned_training_plan_id' => $copy->id, 'assigned_at' => now(), 'status' => 'active']);

                return $copy;
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($paths);
            throw $error;
        }
    }

    public function day(User $user, TrainingDay $source, TrainingPlan $destination, ?User $coach = null): TrainingDay
    {
        $authorize = function () use ($user, $source, $destination, $coach) {
            if ($coach) {
                app(CoachAccess::class)->assignment($coach, $user, $source->trainingPlan, true);
                app(CoachAccess::class)->assignment($coach, $user, $destination, true);
            } else {
                Gate::forUser($user)->authorize('update', $source);
                Gate::forUser($user)->authorize('update', $destination);
            }
        };
        $authorize();

        return $this->copy($user, function (&$paths) use ($user, $source, $destination, $authorize) {
            TrainingPlan::where('user_id', $user->id)->whereIn('id', [$source->training_plan_id, $destination->id])->orderBy('id')->lockForUpdate()->get();
            $source = TrainingDay::lockForUpdate()->findOrFail($source->id);
            $authorize();
            $destination = TrainingPlan::where('user_id', $user->id)->findOrFail($destination->id);

            $destination->forceFill(['schedule_enabled' => false])->save();

            return $this->dayCopy($source, $destination, $this->name($source->title, $destination->trainingDays(), 'title'), ($destination->trainingDays()->max('position') ?? -1) + 1, $paths);
        });
    }

    private function copy(User $user, callable $action): mixed
    {
        $paths = [];
        try {
            return DB::transaction(function () use ($user, $action, &$paths) {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();

                return $action($paths);
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($paths);
            throw $error;
        }
    }

    private function name(string $original, $query, string $field): string
    {
        for ($number = 1; ; $number++) {
            $suffix = ' — Copy'.($number > 1 ? ' '.$number : '');
            $name = Str::substr($original, 0, 150 - Str::length($suffix)).$suffix;
            if (! (clone $query)->where($field, $name)->exists()) {
                return $name;
            }
        }
    }

    private function dayCopy(TrainingDay $source, TrainingPlan $destination, string $title, int $position, array &$paths): TrainingDay
    {
        $copy = $destination->trainingDays()->create([
            ...$source->only('type', 'focus_area', 'icon', 'notes', 'weekdays', 'default_time'),
            'title' => $title, 'position' => $position,
        ]);
        foreach ($source->exercises()->lockForUpdate()->get() as $exercise) {
            $fields = $exercise->only('icon', 'name', 'sets', 'reps', 'target_weight', 'superset_group', 'notes', 'position', 'library_key', 'tracking_mode');
            foreach (['thumbnail_path' => 'exercise-thumbnails', 'custom_icon_path' => 'exercise-icons'] as $field => $directory) {
                $fields[$field] = $this->library->copyMedia($exercise->toArray(), $field, $directory, $paths);
            }
            $copy->exercises()->create($fields);
        }

        return $copy;
    }
}
