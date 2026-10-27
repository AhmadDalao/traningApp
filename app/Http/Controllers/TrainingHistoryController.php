<?php

namespace App\Http\Controllers;

use App\Models\ScheduleEntry;
use App\Services\ExerciseProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TrainingHistoryController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'completed', 'skipped', 'rest', 'planned'])],
            'period' => ['nullable', Rule::in(['all', 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'custom'])],
            'from' => 'exclude_unless:period,custom|required|date_format:Y-m-d',
            'to' => 'exclude_unless:period,custom|required|date_format:Y-m-d|after_or_equal:from',
            'type' => ['nullable', Rule::in(['all', 'training', 'rest'])],
            'per_page' => ['nullable', Rule::in(['10', '25', '50', '100', 'all'])],
            'plan' => ['nullable', 'integer', Rule::exists('training_plans', 'id')->where('user_id', $request->user()->id)],
        ]);
        $period = $filters['period'] ?? 'all';
        $status = $filters['status'] ?? 'all';
        $type = $filters['type'] ?? 'all';
        // Keep older Rest Days filter links working with the separate type filter.
        if ($status === 'rest') {
            $type = 'rest';
            $status = 'all';
        }
        $perPage = $filters['per_page'] ?? '10';
        $dates = match ($period) {
            'today' => [today()->toDateString(), today()->toDateString()],
            'yesterday' => [today()->subDay()->toDateString(), today()->subDay()->toDateString()],
            'last_7_days' => [today()->subDays(6)->toDateString(), today()->toDateString()],
            'last_30_days' => [today()->subDays(29)->toDateString(), today()->toDateString()],
            'this_month' => [today()->startOfMonth()->toDateString(), today()->toDateString()],
            'custom' => [$filters['from'], $filters['to']],
            default => null,
        };
        $query = $request->user()->scheduleEntries()->past();
        if ($dates) {
            $query->whereBetween('scheduled_date', $dates);
        }
        if ($type !== 'all') {
            $query->where('entry_type', $type);
        }
        if (! empty($filters['plan'])) {
            $plan = (int) $filters['plan'];
            $query->where(fn ($query) => $query->where('workout_snapshot->training_plan_id', $plan)
                ->orWhere(fn ($live) => $live->whereNull('workout_snapshot')->whereHas('trainingDay', fn ($day) => $day->where('training_plan_id', $plan))));
        }
        $summary = [
            'completed' => (clone $query)->where('entry_type', 'training')->where('status', 'completed')->count(),
            'skipped' => (clone $query)->where('entry_type', 'training')->where('status', 'skipped')->count(),
            'rest' => (clone $query)->where('entry_type', 'rest')->count(),
            'total' => (clone $query)->count(),
        ];
        if ($status !== 'all') {
            $query->where('entry_type', 'training')->where('status', $status);
        }
        $pageSize = $perPage === 'all' ? max((clone $query)->count(), 1) : (int) $perPage;
        $entries = $query->with([
            'sessionExercises' => fn ($exercises) => $exercises->withCount(['sets', 'sets as completed_sets_count' => fn ($sets) => $sets->whereNotNull('completed_at')]),
            'trainingDay' => fn ($day) => $day->withCount('exercises'),
        ])
            ->orderByDesc('scheduled_date')->orderByDesc('scheduled_time')->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $perPage === 'all' ? 1 : null)->withQueryString()
            ->through(fn (ScheduleEntry $entry) => [
                ...$entry->only('id', 'title', 'entry_type', 'status', 'focus_area', 'icon', 'scheduled_date', 'scheduled_time'),
                'scheduled_date' => $entry->scheduled_date->toDateString(),
                'exercises_count' => $entry->workoutExerciseCount(),
                'detailed_workout' => $entry->sessionExercises->isNotEmpty(),
                'sets_count' => $entry->sessionExercises->sum('sets_count'),
                'completed_sets_count' => $entry->sessionExercises->sum('completed_sets_count'),
                'weights_logged' => $entry->status === 'completed' ? collect($entry->workout_snapshot['exercises'] ?? [])->whereNotNull('actual_weight')->count() : 0,
                'snapshot_available' => $entry->workout_snapshot !== null,
                'duration_minutes' => $entry->status === 'completed' && $entry->entry_type === 'training' ? $entry->duration_minutes : null,
                'calories_burned' => $entry->status === 'completed' && $entry->entry_type === 'training' ? $entry->calories_burned : null,
                'calories_source' => $entry->calories_source,
                'estimated_calories' => $entry->status === 'completed' && $entry->entry_type === 'training' ? $entry->estimated_calories : null,
            ]);

        return Inertia::render('TrainingHistory/Index', [
            'entries' => $entries, 'summary' => $summary,
            'filters' => [
                'status' => $status, 'type' => $type, 'period' => $period,
                'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '',
                'per_page' => (string) $perPage, 'plan' => $filters['plan'] ?? '',
            ],
            'plans' => $request->user()->trainingPlans()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, ScheduleEntry $scheduleEntry, ExerciseProgress $progress)
    {
        Gate::authorize('view', $scheduleEntry);
        abort_unless($request->user()->scheduleEntries()->past()->whereKey($scheduleEntry->id)->exists(), 404);
        $scheduleEntry->load('trainingDay.exercises', 'trainingDay.trainingPlan', 'sessionExercises.sets');

        return Inertia::render('ScheduleEntries/Show', ['entry' => $scheduleEntry, 'workout' => $scheduleEntry->workoutDetails(), 'history' => true, 'progressSummary' => $progress->forSession($request->user(), $scheduleEntry)]);
    }
}
