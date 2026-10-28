<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTrainingDayRequest;
use App\Http\Requests\UpdateTrainingDayRequest;
use App\Models\TrainingDay;
use App\Models\TrainingPlan;
use App\Services\ExerciseLibrary;
use App\Services\TrainingPlanDuplicator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TrainingDayController extends Controller
{
    public function create(TrainingPlan $trainingPlan)
    {
        Gate::authorize('update', $trainingPlan);

        return Inertia::render('TrainingDays/Form', ['plan' => $trainingPlan, 'customExercises' => app(ExerciseLibrary::class)->customItems($trainingPlan->user_id)]);
    }

    public function store(StoreTrainingDayRequest $request, TrainingPlan $trainingPlan)
    {
        $day = $this->saveDay($request, $trainingPlan);

        return to_route('training-days.show', $day)->with('success', 'Training day created.')
            ->with('schedule_review', $trainingPlan->only('id', 'name'));
    }

    public function show(TrainingDay $trainingDay)
    {
        Gate::authorize('view', $trainingDay);

        return Inertia::render('TrainingDays/Show', ['day' => $trainingDay->load('trainingPlan', 'exercises'),
            'copyPlans' => $trainingDay->trainingPlan->user->trainingPlans()->whereKeyNot($trainingDay->training_plan_id)->orderBy('name')->get(['id', 'name'])]);
    }

    public function edit(TrainingDay $trainingDay)
    {
        Gate::authorize('update', $trainingDay);

        return Inertia::render('TrainingDays/Form', ['day' => $trainingDay->load('exercises'), 'plan' => $trainingDay->trainingPlan, 'customExercises' => app(ExerciseLibrary::class)->customItems($trainingDay->trainingPlan->user_id)]);
    }

    public function update(UpdateTrainingDayRequest $request, TrainingDay $trainingDay)
    {
        $fields = ['title', 'type', 'focus_area', 'icon', 'notes', 'weekdays', 'default_time'];
        $before = $trainingDay->only($fields);
        $this->saveDay($request, $trainingDay->trainingPlan, $trainingDay);

        return to_route('training-days.show', $trainingDay)->with('success', 'Training day updated.')
            ->with('schedule_review', $before !== $trainingDay->fresh()->only($fields) ? $trainingDay->trainingPlan->only('id', 'name') : null);
    }

    public function duplicate(Request $request, TrainingDay $trainingDay, TrainingPlanDuplicator $duplicator)
    {
        Gate::authorize('update', $trainingDay);
        $data = $request->validate(['confirmed' => 'required|accepted', 'training_plan_id' => 'nullable|integer']);
        $destination = isset($data['training_plan_id']) ? TrainingPlan::findOrFail($data['training_plan_id']) : $trainingDay->trainingPlan;
        $copy = $duplicator->day($request->user(), $trainingDay, $destination);

        return to_route('training-days.show', $copy)->with('success', 'Training day copied independently. No Calendar sessions were copied.')
            ->with('schedule_review', $destination->only('id', 'name'));
    }

    private function saveDay(StoreTrainingDayRequest $request, TrainingPlan $plan, ?TrainingDay $day = null): TrainingDay
    {
        $data = $request->validated();
        $newPaths = [];
        $oldPaths = [];
        try {
            $day = DB::transaction(function () use ($data, $plan, $day, &$newPaths, &$oldPaths) {
                TrainingPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
                if ($day) {
                    $day = TrainingDay::lockForUpdate()->findOrFail($day->id);
                }
                $fields = collect($data)->only('title', 'type', 'focus_area', 'notes', 'icon', 'weekdays', 'default_time')->all();
                if ($fields['type'] === 'rest') {
                    $fields['focus_area'] = null;
                }
                if ($day) {
                    $day->update($fields);
                } else {
                    $day = $plan->trainingDays()->create([...$fields, 'position' => ($plan->trainingDays()->max('position') ?? -1) + 1]);
                }
                $existing = $day->exercises()->get()->keyBy('id');
                $library = app(ExerciseLibrary::class);
                $kept = [];
                foreach ($data['type'] === 'training' ? ($data['exercises'] ?? []) : [] as $position => $row) {
                    $exercise = ! empty($row['id']) ? $existing->get($row['id']) : $day->exercises()->make();
                    $fields = collect($row)->only('name', 'sets', 'reps', 'notes', 'icon', 'target_weight', 'library_key', 'tracking_mode')->all();
                    $fields['tracking_mode'] = $fields['tracking_mode'] ?? $exercise->tracking_mode ?? 'reps';
                    $fields['position'] = $position;
                    $fields['superset_group'] = empty($row['superset_group']) ? null : strtoupper($row['superset_group']);
                    $sourceChanged = array_key_exists('library_key', $row) && ($row['library_key'] ?? null) !== $exercise->library_key;
                    $source = $sourceChanged ? $library->find($row['library_key'] ?? null, $plan->user_id) : null;
                    foreach (['thumbnail' => 'exercise-thumbnails', 'custom_icon' => 'exercise-icons'] as $media => $directory) {
                        $column = $media.'_path';
                        if ($sourceChanged || ! empty($row[$media]) || ! empty($row['remove_'.$media])) {
                            if ($exercise->$column) {
                                $oldPaths[] = $exercise->$column;
                            }
                            if (! empty($row[$media])) {
                                $fields[$column] = $row[$media]->store($directory, 'public');
                                $newPaths[] = $fields[$column];
                            } else {
                                $fields[$column] = ! empty($row['remove_'.$media]) ? null : $library->copyMedia($source, $column, $directory, $newPaths);
                            }
                        }
                    }
                    $exercise->fill($fields)->save();
                    if (! empty($row['save_to_library']) && ! $exercise->library_key) {
                        $custom = $library->saveCustom($exercise, $plan->user_id, $day->focus_area, $newPaths);
                        $exercise->update(['library_key' => 'custom:'.$custom->id]);
                    }
                    $kept[] = $exercise->id;
                }
                foreach ($existing as $exercise) {
                    if (! in_array($exercise->id, $kept)) {
                        if ($exercise->thumbnail_path) {
                            $oldPaths[] = $exercise->thumbnail_path;
                        }
                        if ($exercise->custom_icon_path) {
                            $oldPaths[] = $exercise->custom_icon_path;
                        }
                        $exercise->delete();
                    }
                }

                return $day;
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($newPaths);
            throw $error;
        }
        Storage::disk('public')->delete($oldPaths);

        return $day;
    }

    public function destroy(TrainingDay $trainingDay)
    {
        Gate::authorize('delete', $trainingDay);
        $plan = $trainingDay->training_plan_id;
        $paths = $trainingDay->exercises()->get()->flatMap(fn ($exercise) => [$exercise->thumbnail_path, $exercise->custom_icon_path])->filter()->all();
        DB::transaction(function () use ($trainingDay) {
            TrainingPlan::whereKey($trainingDay->training_plan_id)->lockForUpdate()->firstOrFail();
            $trainingDay->delete();
        });
        Storage::disk('public')->delete($paths);

        return to_route('training-plans.show', $plan)->with('success', 'Training day deleted. Calendar history is preserved.')
            ->with('schedule_review', TrainingPlan::findOrFail($plan)->only('id', 'name'));
    }

    public function reorderDays(Request $request, TrainingPlan $trainingPlan)
    {
        Gate::authorize('update', $trainingPlan);
        $this->reorder($request, $trainingPlan->trainingDays());

        return back();
    }

    public function reorderExercises(Request $request, TrainingDay $trainingDay)
    {
        Gate::authorize('update', $trainingDay);
        $this->reorder($request, $trainingDay->exercises());

        return back();
    }

    private function reorder(Request $request, $relation): void
    {
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'required|integer|distinct'])['ids'];
        $actual = $relation->pluck('id')->all();
        $sorted = array_map('intval', $ids);
        sort($actual);
        sort($sorted);
        if ($actual !== $sorted) {
            throw ValidationException::withMessages(['ids' => 'The order must contain every item in this list exactly once.']);
        }
        DB::transaction(function () use ($relation, $ids) {
            foreach ($ids as $position => $id) {
                (clone $relation)->whereKey($id)->update(['position' => $position]);
            }
        });
    }
}
