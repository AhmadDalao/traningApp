<?php

namespace App\Services;

use App\Models\ProgramRun;
use App\Models\SessionExercise;
use Illuminate\Support\Facades\DB;

class ProgramLifecycle
{
    public function cards($query): array
    {
        return $query->latest('id')->limit(6)->get()->map(function ($run) {
            $run = $this->sync($run->id);
            $position = $this->position($run);

            return ['id' => $run->id, 'name' => $run->definition_snapshot['name'], 'status' => $run->status,
                'current_week' => $position['current_week'], 'duration_weeks' => $position['duration_weeks'],
                'block' => $position['current_block']['name'] ?? null, 'adherence' => $position['adherence']];
        })->all();
    }

    public function position(ProgramRun $run): array
    {
        $sessions = $run->sessions()->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get();
        $required = $sessions->where('entry_type', 'training');
        $unresolved = $required->filter(fn ($entry) => ! in_array($entry->status, ['completed', 'skipped'], true) || $entry->isActiveWorkout());
        $current = $unresolved->min('program_week');
        $duration = (int) $run->definition_snapshot['duration_weeks'];
        $resolvedWeeks = collect(range(1, $duration))->filter(fn ($week) => ! $unresolved->contains('program_week', $week))->count();
        $due = $required->filter(fn ($entry) => ! app(CoachAccess::class)->future($entry->scheduled_date->toDateString(), $entry->scheduled_time));
        $completed = $due->where('status', 'completed')->count();
        $missed = $due->filter(fn ($entry) => $entry->status === 'planned' && ! $entry->started_at)->count();
        $block = collect($run->definition_snapshot['blocks'])->first(fn ($block) => $current !== null && $block['start_week'] <= $current && $block['end_week'] >= $current);

        $next = $unresolved->first();

        return ['current_week' => $current === null ? null : (int) $current, 'current_block' => $block,
            'resolved_weeks' => $resolvedWeeks, 'duration_weeks' => $duration,
            'progress_percent' => round($resolvedWeeks / $duration * 100),
            'total_workouts' => $required->count(), 'completed_workouts' => $required->where('status', 'completed')->count(),
            'skipped_workouts' => $required->where('status', 'skipped')->count(), 'due' => $due->count(),
            'due_completed' => $completed, 'due_skipped' => $due->where('status', 'skipped')->count(), 'missed' => $missed,
            'adherence' => $due->isEmpty() ? null : round($completed / $due->count() * 100),
            'all_resolved' => $required->isNotEmpty() && $unresolved->isEmpty() && ! $sessions->contains(fn ($entry) => $entry->isActiveWorkout()),
            'next_workout' => $next ? [...$next->only('id', 'title', 'scheduled_time', 'program_week', 'started_at'), 'scheduled_date' => $next->scheduled_date->toDateString()] : null,
            'sessions' => $sessions->map(fn ($entry) => [...$entry->only('id', 'title', 'entry_type', 'status', 'scheduled_date', 'scheduled_time', 'program_week', 'program_context', 'started_at'),
                'scheduled_date' => $entry->scheduled_date->toDateString(),
                'missed' => $entry->entry_type === 'training' && $entry->status === 'planned' && ! $entry->started_at && ! app(CoachAccess::class)->future($entry->scheduled_date->toDateString(), $entry->scheduled_time),
            ])->all()];
    }

    public function sync(int $id): ?ProgramRun
    {
        return DB::transaction(function () use ($id) {
            $run = ProgramRun::lockForUpdate()->find($id);
            if (! $run || $run->status === 'ended') {
                return $run;
            }
            $run->sessions()->lockForUpdate()->get(['id']);
            $complete = $this->position($run)['all_resolved'];
            $run->fill(['status' => $complete ? 'completed' : 'active', 'completed_at' => $complete ? ($run->completed_at ?? now()) : null]);
            if ($run->isDirty()) {
                $run->save();
            }

            return $run;
        });
    }

    public function summary(ProgramRun $run): array
    {
        $service = app(ExerciseProgress::class);
        $ids = $run->sessions()->where('status', 'completed')->pluck('id')->all();
        $groups = $service->workouts($run->user)->groupBy(fn ($row) => $row['source_exercise_key'].'|'.$row['tracking_mode']);
        $events = [];
        $gains = [];
        foreach ($groups as $rows) {
            foreach ($service->records($rows)['events'] as $event) {
                if (in_array($event['session_id'], $ids, true)) {
                    $events[] = $event;
                }
            }
            $during = $rows->whereIn('session_id', $ids)->whereNotNull('heaviest_weight')->values();
            if ($during->count() > 1) {
                $change = round($during->last()['heaviest_weight'] - $during->first()['heaviest_weight'], 2);
                if ($change != 0) {
                    $exercise = SessionExercise::find($during->last()['exercise_id']);
                    $gains[] = ['name' => $exercise->name, 'change_kg' => $change, 'url' => $service->url($exercise->source_exercise_key, $exercise->tracking_mode, $run->user)];
                }
            }
        }
        $weight = app(BodyWeightHistory::class);
        $start = $weight->onOrBefore($run->user, $run->started_on->toDateString());
        $end = $run->completed_at ? $weight->onOrBefore($run->user, $run->completed_at->toDateString()) : null;

        return ['pr_count' => count($events), 'records' => $events, 'weight_gains' => $gains,
            'body_weight_change' => $start && $end && $start->id !== $end->id ? round($end->weight_kg - $start->weight_kg, 2) : null];
    }
}
