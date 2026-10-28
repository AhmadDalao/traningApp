<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScheduleEntryRequest;
use App\Http\Requests\UpdateScheduleEntryRequest;
use App\Http\Requests\UpdateScheduleEntryStatusRequest;
use App\Http\Requests\UpdateWorkoutWeightsRequest;
use App\Models\ScheduleEntry;
use App\Models\TrainingDay;
use App\Models\User;
use App\Services\CoachAccess;
use App\Services\ExerciseProgress;
use App\Services\TrainingContext;
use App\Services\WorkoutCalorieEstimator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ScheduleEntryController extends Controller
{
    public function calendar(Request $request)
    {
        $request->validate(['date' => 'nullable|date_format:Y-m-d', 'view' => 'nullable|in:dayGridMonth,dayGridWeek,listMonth']);
        $date = Carbon::parse($request->input('date', now()->toDateString()));
        $start = $date->copy()->startOfMonth()->subWeek();
        $end = $date->copy()->endOfMonth()->addWeek();

        return Inertia::render('Calendar/Index', [
            'entries' => app(TrainingContext::class)->owner()->scheduleEntries()->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])->orderBy('scheduled_date')->orderBy('scheduled_time')->get(['id', 'title', 'entry_type', 'focus_area', 'icon', 'scheduled_date', 'scheduled_time', 'status']),
            'calendarDate' => $date->toDateString(), 'calendarView' => $request->input('view', 'dayGridMonth'),
            'plans' => $this->plans($request),
        ]);
    }

    private function plans(Request $request)
    {
        return app(TrainingContext::class)->plans(true)->with('trainingDays')->orderBy('name')->get();
    }

    public function create(Request $request)
    {
        return Inertia::render('ScheduleEntries/Form', ['plans' => $this->plans($request), 'selectedDay' => $request->integer('day'), 'selectedDate' => $request->input('date', now()->toDateString())]);
    }

    public function store(StoreScheduleEntryRequest $request)
    {
        $entry = DB::transaction(function () use ($request) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            return $request->user()->scheduleEntries()->create([...$this->entryData($request), 'status' => 'planned', 'scheduled_by_user_id' => $request->user()->id]);
        });

        return to_route('schedule-entries.show', $entry)->with('success', 'Session added to your calendar.');
    }

    private function entryData(StoreScheduleEntryRequest $request, ?ScheduleEntry $entry = null): array
    {
        $data = $request->validated();
        if ($entry && $entry->workout_snapshot !== null) {
            if ((int) ($data['training_day_id'] ?? 0) !== (int) $entry->training_day_id || $data['entry_type'] !== $entry->entry_type) {
                throw ValidationException::withMessages(['training_day_id' => 'This session has a saved workout. Create a new session to use a different training day.']);
            }

            return ['title' => $data['title'] ?? $entry->title, 'scheduled_date' => $data['scheduled_date'], 'scheduled_time' => $data['scheduled_time'] ?? null, 'notes' => $data['notes'] ?? null];
        }
        $day = ! empty($data['training_day_id']) ? TrainingDay::whereHas('trainingPlan', fn ($q) => $q->where('user_id', $request->user()->id))->findOrFail($data['training_day_id']) : null;

        return [
            'training_day_id' => $day?->id,
            'icon' => $day ? $day->icon : ($data['entry_type'] === 'training' ? $entry?->icon : null),
            'title' => $data['title'] ?? $day?->title ?? $entry?->title ?? 'Rest Day',
            'entry_type' => $day?->type ?? $data['entry_type'],
            'focus_area' => $day ? $day->focus_area : ($data['entry_type'] === 'training' ? $entry?->focus_area : null),
            'scheduled_date' => $data['scheduled_date'], 'scheduled_time' => $data['scheduled_time'] ?? null, 'notes' => $data['notes'] ?? null,
        ];
    }

    public function show(ScheduleEntry $scheduleEntry, ExerciseProgress $progress)
    {
        $context = app(TrainingContext::class);
        if ($context->coaching()) {
            app(CoachAccess::class)->relationship($context->actor(), $context->owner());
            abort_unless($scheduleEntry->user_id === $context->owner()->id, 403);
        } else {
            Gate::authorize('view', $scheduleEntry);
        }

        $scheduleEntry->load('trainingDay.exercises', 'trainingDay.trainingPlan', 'sessionExercises.sets');

        return Inertia::render('ScheduleEntries/Show', ['entry' => $scheduleEntry, 'workout' => $scheduleEntry->workoutDetails(), 'progressSummary' => $progress->forSession($context->owner(), $scheduleEntry), 'scheduledBy' => $scheduleEntry->scheduledBy?->coachProfile?->display_name, 'canManageSession' => ! $context->coaching() || app(CoachAccess::class)->canManageSession($context->actor(), $context->owner(), $scheduleEntry)]);
    }

    public function edit(Request $request, ScheduleEntry $scheduleEntry)
    {
        Gate::authorize('update', $scheduleEntry);

        return Inertia::render('ScheduleEntries/Form', ['entry' => $scheduleEntry, 'plans' => $this->plans($request)]);
    }

    public function update(UpdateScheduleEntryRequest $request, ScheduleEntry $scheduleEntry, WorkoutCalorieEstimator $estimator)
    {
        DB::transaction(function () use ($request, $scheduleEntry, $estimator) {
            $entry = $request->user()->scheduleEntries()->lockForUpdate()->findOrFail($scheduleEntry->id);
            if ($request->boolean('details_only')) {
                if ($entry->status !== 'completed' || $entry->entry_type !== 'training') {
                    throw ValidationException::withMessages(['details' => 'Workout details can only be recorded for a completed training session.']);
                }
                $data = $request->validated();
                $reported = array_key_exists('calories_burned', $data) ? $data['calories_burned'] : $entry->calories_burned;
                $entry->fill([
                    'duration_minutes' => array_key_exists('duration_minutes', $data) ? $data['duration_minutes'] : $entry->duration_minutes,
                    'intensity' => $data['intensity'] ?? $entry->intensity ?? 'moderate',
                    'calories_burned' => $reported,
                    'calories_source' => $reported !== null ? ($data['calories_source'] ?? $entry->calories_source ?? 'manual') : null,
                ]);
                if ($entry->estimated_calories === null || $entry->isDirty(['duration_minutes', 'intensity'])) {
                    $entry->estimated_calories = $estimator->forSession($entry);
                }
                $entry->save();
            } else {
                $entry->fill($this->entryData($request, $entry));
                if ($entry->plan_occurrence_key && $entry->isDirty(['title', 'training_day_id', 'entry_type', 'focus_area', 'icon', 'scheduled_date', 'scheduled_time', 'notes'])) {
                    // The original key still prevents a duplicate after a manual move.
                    $entry->schedule_managed = false;
                }
                if ($entry->isDirty(['entry_type', 'focus_area', 'scheduled_date'])) {
                    $entry->estimated_calories = $estimator->forSession($entry);
                    if ($entry->estimated_calories !== null) {
                        $entry->intensity ??= 'moderate';
                    }
                }
                $entry->save();
            }
        });

        if ($request->boolean('details_only')) {
            return back()->with('success', 'Workout details saved.');
        }

        return to_route('schedule-entries.show', $scheduleEntry)->with('success', 'Session updated.');
    }

    public function status(UpdateScheduleEntryStatusRequest $request, ScheduleEntry $scheduleEntry, WorkoutCalorieEstimator $estimator)
    {
        $paths = [];
        $status = $request->validated('status');
        try {
            DB::transaction(function () use ($request, $scheduleEntry, $status, $estimator, &$paths) {
                $entry = $request->user()->scheduleEntries()->lockForUpdate()->findOrFail($scheduleEntry->id);
                if ($entry->isActiveWorkout() && $status !== 'planned') {
                    throw ValidationException::withMessages(['status' => 'This workout is in progress. Finish or discard it from Active Workout.']);
                }
                if (! $entry->isActiveWorkout() && $status !== 'completed') {
                    $entry->started_at = null;
                }
                if ($status === 'completed') {
                    if ($entry->workout_snapshot === null) {
                        $entry->workout_snapshot = $entry->captureWorkoutSnapshot($paths);
                    }
                    if ($entry->status !== 'completed' || $entry->completed_at === null) {
                        $entry->completed_at = now();
                    }
                } else {
                    $entry->completed_at = null;
                }
                $entry->status = $status;
                if ($status !== 'completed' || $entry->entry_type !== 'training' || $entry->estimated_calories === null) {
                    $entry->estimated_calories = $estimator->forSession($entry);
                    if ($entry->estimated_calories !== null) {
                        $entry->intensity ??= 'moderate';
                    }
                }
                $entry->save();
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($paths);
            throw $error;
        }

        return back()->with('success', 'Session marked '.$status.'.');
    }

    public function weights(UpdateWorkoutWeightsRequest $request, ScheduleEntry $scheduleEntry)
    {
        DB::transaction(function () use ($request, $scheduleEntry) {
            $entry = $request->user()->scheduleEntries()->lockForUpdate()->findOrFail($scheduleEntry->id);
            if ($entry->status !== 'completed' || $entry->entry_type !== 'training' || $entry->workout_snapshot === null) {
                throw ValidationException::withMessages(['weights' => 'Actual weights can only be recorded for a completed training session with a saved workout.']);
            }
            if ($entry->sessionExercises()->exists()) {
                throw ValidationException::withMessages(['weights' => 'This workout has detailed per-set weights. Use its structured set logs.']);
            }
            $snapshot = $entry->workout_snapshot;
            foreach ($request->validated('weights') as $row => $weight) {
                if (! array_key_exists($weight['index'], $snapshot['exercises'] ?? [])) {
                    throw ValidationException::withMessages(["weights.$row.index" => 'Choose an exercise in this saved workout.']);
                }
                $snapshot['exercises'][$weight['index']]['actual_weight'] = $weight['actual_weight'] === null ? null : number_format((float) $weight['actual_weight'], 2, '.', '');
            }
            $entry->workout_snapshot = $snapshot;
            $entry->save();
        });

        return back()->with('success', 'Actual weights saved.');
    }

    public function destroy(ScheduleEntry $scheduleEntry)
    {
        Gate::authorize('delete', $scheduleEntry);
        $paths = DB::transaction(function () use ($scheduleEntry) {
            $entry = ScheduleEntry::lockForUpdate()->findOrFail($scheduleEntry->id);
            $paths = $entry->snapshotThumbnailPaths();
            $entry->delete();

            return $paths;
        });
        Storage::disk('public')->delete($paths);

        return to_route('calendar')->with('success', 'Session deleted.');
    }
}
