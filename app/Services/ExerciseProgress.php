<?php

namespace App\Services;

use App\Models\ScheduleEntry;
use App\Models\SessionExercise;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExerciseProgress
{
    private const LABELS = [
        'heaviest_weight' => 'Heaviest Weight', 'best_set' => 'Best Set', 'max_reps' => 'Max Reps',
        'weighted_volume' => 'Best Session Volume', 'total_reps' => 'Most Workout Reps',
        'longest_duration' => 'Longest Set', 'total_duration' => 'Longest Workout Duration',
    ];

    public function query(User $user, ?string $key = null): Builder
    {
        return DB::table('session_exercises as exercise')
            ->join('schedule_entries as session', 'session.id', '=', 'exercise.schedule_entry_id')
            ->join('session_sets as actual', 'actual.session_exercise_id', '=', 'exercise.id')
            ->where('session.user_id', $user->id)->where('session.entry_type', 'training')->where('session.status', 'completed')
            ->where('exercise.status', '!=', 'skipped')->whereNotNull('exercise.source_exercise_key')
            ->where('exercise.source_exercise_key', '!=', '')
            ->when($key !== null, fn ($query) => $query->where('exercise.source_exercise_key', $key))
            ->whereNotNull('actual.completed_at')
            ->where(fn ($query) => $query
                ->where(fn ($reps) => $reps->where('exercise.tracking_mode', 'reps')->where('actual.actual_reps', '>', 0))
                ->orWhere(fn ($duration) => $duration->where('exercise.tracking_mode', 'duration')->where('actual.actual_duration_seconds', '>', 0)))
            ->select([
                'exercise.id as exercise_id', 'exercise.source_exercise_key', 'exercise.tracking_mode',
                'session.id as session_id', 'session.title as session_title', 'session.scheduled_date', 'session.scheduled_time',
                'actual.id as set_id', 'actual.set_number', 'actual.actual_reps', 'actual.actual_weight_kg', 'actual.actual_duration_seconds',
            ]);
    }

    /** SQL returns one aggregate per exercise/mode/workout, not every historical set. */
    public function workouts(User $user, ?string $key = null, ?string $mode = null, array $keys = []): Collection
    {
        $sets = $this->query($user, $key)
            ->when($mode, fn ($q) => $q->where('exercise.tracking_mode', $mode))
            ->when($keys, fn ($q) => $q->whereIn('exercise.source_exercise_key', $keys))
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY exercise.source_exercise_key, exercise.tracking_mode, session.id ORDER BY actual.actual_weight_kg DESC, actual.actual_reps DESC, actual.id) AS weight_rank')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY exercise.source_exercise_key, exercise.tracking_mode, session.id ORDER BY actual.actual_reps DESC, actual.actual_weight_kg DESC, actual.id) AS reps_rank');
        $weighted = "tracking_mode = 'reps' AND actual_weight_kg > 0";

        return DB::query()->fromSub($sets, 'results')
            ->select('source_exercise_key', 'tracking_mode', 'session_id', 'session_title', 'scheduled_date', 'scheduled_time')
            ->selectRaw('MAX(exercise_id) AS exercise_id, COUNT(*) AS completed_sets')
            ->selectRaw("MAX(CASE WHEN $weighted THEN actual_weight_kg END) AS heaviest_weight")
            ->selectRaw("MAX(CASE WHEN $weighted AND weight_rank = 1 THEN actual_reps END) AS best_reps")
            ->selectRaw("MAX(CASE WHEN $weighted AND weight_rank = 1 THEN set_id END) AS best_set_id")
            ->selectRaw("SUM(CASE WHEN $weighted THEN actual_weight_kg * actual_reps END) AS weighted_volume")
            ->selectRaw("MAX(CASE WHEN tracking_mode = 'reps' THEN actual_reps END) AS max_reps")
            ->selectRaw("MAX(CASE WHEN tracking_mode = 'reps' AND reps_rank = 1 AND actual_weight_kg > 0 THEN actual_weight_kg END) AS max_reps_weight")
            ->selectRaw("SUM(CASE WHEN tracking_mode = 'reps' THEN actual_reps END) AS total_reps")
            ->selectRaw("MAX(CASE WHEN tracking_mode = 'duration' THEN actual_duration_seconds END) AS longest_duration")
            ->selectRaw("SUM(CASE WHEN tracking_mode = 'duration' THEN actual_duration_seconds END) AS total_duration")
            ->groupBy('source_exercise_key', 'tracking_mode', 'session_id', 'session_title', 'scheduled_date', 'scheduled_time')
            ->orderBy('scheduled_date')->orderByRaw("COALESCE(scheduled_time, '00:00:00')")->orderBy('session_id')
            ->get()->map(function ($row) {
                $row = (array) $row;
                // SQLite can retain midnight when a date was saved through a Carbon instance.
                $row['scheduled_date'] = substr($row['scheduled_date'], 0, 10);
                foreach (['heaviest_weight', 'weighted_volume', 'max_reps_weight'] as $field) {
                    $row[$field] = $row[$field] === null ? null : round((float) $row[$field], 2);
                }
                foreach (['max_reps', 'total_reps', 'longest_duration', 'total_duration', 'completed_sets'] as $field) {
                    $row[$field] = $row[$field] === null ? null : (int) $row[$field];
                }
                $row['best_set'] = $row['heaviest_weight'] === null ? null : ['weight' => $row['heaviest_weight'], 'reps' => (int) $row['best_reps'], 'set_id' => (int) $row['best_set_id']];
                unset($row['best_reps'], $row['best_set_id']);

                return $row;
            });
    }

    public function records(Collection $workouts): array
    {
        $mode = $workouts->first()['tracking_mode'] ?? 'reps';
        $weighted = $workouts->whereNotNull('heaviest_weight')->isNotEmpty();
        $fields = $mode === 'duration' ? ['longest_duration', 'total_duration']
            : ($weighted ? ['heaviest_weight', 'best_set', 'max_reps', 'weighted_volume'] : ['max_reps', 'total_reps']);
        $records = [];
        $events = [];
        $first = $workouts->first()['session_id'] ?? null;
        foreach ($workouts as $workout) {
            $weightImproved = isset($records['heaviest_weight']) && $workout['heaviest_weight'] > $records['heaviest_weight']['value'];
            foreach ($fields as $field) {
                $value = $workout[$field];
                if ($value === null) {
                    continue;
                }
                $previous = $records[$field]['value'] ?? null;
                $better = $previous === null || ($field === 'best_set'
                    ? ([$value['weight'], $value['reps']] > [$previous['weight'], $previous['reps']]) : $value > $previous);
                if (! $better) {
                    continue;
                }
                $record = [
                    'metric' => $field, 'label' => self::LABELS[$field], 'value' => $value,
                    'session_id' => $workout['session_id'], 'date' => $workout['scheduled_date'], 'time' => $workout['scheduled_time'],
                    'weight' => $field === 'max_reps' ? $workout['max_reps_weight'] : null,
                    'source_exercise_key' => $workout['source_exercise_key'], 'tracking_mode' => $mode,
                ];
                $records[$field] = $record;
                // First measurements establish a baseline; equal values are not new records.
                // A heavier Best Set is announced once as a Weight PR, not twice.
                if ($previous !== null && ! ($field === 'best_set' && $weightImproved)) {
                    $events[] = $record;
                }
            }
        }

        return ['records' => array_values($records), 'events' => $events, 'first_session_id' => $first, 'weighted' => $weighted];
    }

    public function overview(User $user, string $search = ''): array
    {
        $groups = $this->workouts($user)->groupBy(fn ($row) => $this->identity($row));
        $metadata = SessionExercise::whereIn('id', $groups->map(fn ($rows) => $rows->last()['exercise_id']))->get()->keyBy('id');
        $recent = [];
        $items = [];
        foreach ($groups as $rows) {
            $latest = $rows->last();
            $exercise = $metadata[$latest['exercise_id']];
            $records = $this->records($rows);
            $item = $this->summary($rows, $exercise, $records, $user);
            foreach ($records['events'] as $event) {
                $recent[] = [...$event, 'name' => $exercise->name, 'url' => $item['url']];
            }
            if ($search === '' || mb_stripos($exercise->name, $search) !== false) {
                $items[] = $item;
            }
        }
        $items = collect($items)->sortByDesc(fn ($item) => [$item['last_performed'], $item['last_session_id']])->values();
        $recent = collect($recent)->sortByDesc(fn ($event) => [$event['date'], $event['time'] ?? '00:00:00', $event['session_id']])->take(8)->values();

        return ['exercises' => $this->paginate($items, 12), 'recentRecords' => $recent, 'trackedCount' => $groups->count()];
    }

    public function detail(User $user, string $key, string $mode, string $range): array
    {
        $workouts = $this->workouts($user, $key, $mode);
        abort_if($workouts->isEmpty(), 404);
        $exercise = SessionExercise::findOrFail($workouts->last()['exercise_id']);
        $records = $this->records($workouts);
        $cutoff = match ($range) {
            '30' => today()->subDays(29), '90' => today()->subDays(89),
            '6m' => today()->subMonthsNoOverflow(6), '1y' => today()->subYearNoOverflow(), default => null,
        };
        $filtered = $workouts->filter(fn ($row) => $cutoff === null || ($row['scheduled_date'] >= $cutoff->toDateString() && $row['scheduled_date'] <= today()->toDateString()))->values();
        $chartFields = $mode === 'duration' ? ['longest_duration'] : ($records['weighted'] ? ['heaviest_weight', 'weighted_volume'] : ['max_reps']);
        $charts = [];
        foreach ($chartFields as $field) {
            $charts[] = ['metric' => $field, 'points' => $filtered->whereNotNull($field)->map(fn ($row) => [
                'session_id' => $row['session_id'], 'date' => $row['scheduled_date'], 'value' => $row[$field],
            ])->values()];
        }
        $history = $this->paginate($filtered->reverse()->values(), 10);
        // Fetch actual sets only for the displayed history page.
        $sets = $this->query($user, $key)->where('exercise.tracking_mode', $mode)
            ->whereIn('session.id', collect($history->items())->pluck('session_id'))
            ->orderBy('exercise.position')->orderBy('actual.set_number')->get()->groupBy('session_id');
        $history->through(fn ($row) => [...$row, 'sets' => ($sets[$row['session_id']] ?? collect())->values()]);

        return [
            'exercise' => $this->summary($workouts, $exercise, $records, $user), 'records' => $records['records'],
            'charts' => $charts, 'history' => $history, 'range' => $range,
        ];
    }

    public function forSession(User $user, ScheduleEntry $entry): ?array
    {
        if ($entry->status !== 'completed' || $entry->entry_type !== 'training') {
            return null;
        }
        $eligible = $this->query($user)->where('session.id', $entry->id)
            ->select('exercise.id as exercise_id', 'exercise.source_exercise_key', 'exercise.tracking_mode')->distinct()->get();
        if ($eligible->isEmpty()) {
            return null;
        }
        $keys = $eligible->pluck('source_exercise_key')->unique()->all();
        $groups = $this->workouts($user, keys: $keys)->groupBy(fn ($row) => $this->identity($row));
        $names = $entry->sessionExercises->keyBy('id');
        $events = [];
        $first = [];
        $links = [];
        foreach ($eligible->unique('exercise_id') as $set) {
            $links[$set->exercise_id] = $this->url($set->source_exercise_key, $set->tracking_mode, $user);
        }
        foreach ($eligible->unique(fn ($set) => $set->source_exercise_key.'|'.$set->tracking_mode) as $set) {
            $records = $this->records($groups[$set->source_exercise_key.'|'.$set->tracking_mode]);
            $name = $names[$set->exercise_id]->name;
            if ($records['first_session_id'] === $entry->id) {
                $first[] = $name;
            }
            foreach ($records['events'] as $event) {
                if ($event['session_id'] === $entry->id) {
                    $events[] = [...$event, 'name' => $name, 'url' => $links[$set->exercise_id]];
                }
            }
        }

        return ['records' => $events, 'firstPerformances' => $first, 'links' => $links];
    }

    private function summary(Collection $workouts, SessionExercise $exercise, array $records, User $user): array
    {
        $latest = $workouts->last();

        return [
            'key' => $exercise->source_exercise_key, 'name' => $exercise->name, 'tracking_mode' => $exercise->tracking_mode,
            'icon' => $exercise->icon, 'thumbnail_url' => $exercise->thumbnail_url, 'custom_icon_url' => $exercise->custom_icon_url,
            'workouts' => $workouts->count(), 'last_performed' => $latest['scheduled_date'], 'last_session_id' => $latest['session_id'],
            'latest_weight' => $latest['heaviest_weight'], 'weighted' => $records['weighted'], 'records' => $records['records'],
            'url' => $this->url($exercise->source_exercise_key, $exercise->tracking_mode, $user),
        ];
    }

    private function paginate(Collection $rows, int $perPage): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();

        return (new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]))->withQueryString();
    }

    private function identity(array $row): string
    {
        return $row['source_exercise_key'].'|'.$row['tracking_mode'];
    }

    public function url(string $key, string $mode, ?User $subject = null): string
    {
        $prefix = request()->attributes->get('coach_workspace') && $subject && request()->user()?->id !== $subject->id ? '/coach/trainees/'.$subject->id : '';

        return $prefix.'/progress/exercises?'.http_build_query(['exercise' => $key, 'mode' => $mode], '', '&', PHP_QUERY_RFC3986);
    }
}
