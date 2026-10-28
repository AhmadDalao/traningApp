<?php

namespace App\Services;

use App\Models\ScheduleEntry;
use App\Models\SessionExercise;
use App\Models\TrainingDay;
use App\Models\TrainingExercise;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ExerciseProgression
{
    public function __construct(private ProgressionPrescription $parser) {}

    private function templates(User $user, string $key, string $mode)
    {
        return TrainingExercise::where('library_key', $key)
            ->where(fn ($q) => $q->where('tracking_mode', $mode)->when($mode === 'reps', fn ($q) => $q->orWhereNull('tracking_mode')))
            ->whereHas('trainingDay', fn ($q) => $q->where('type', 'training')
                ->whereHas('trainingPlan', fn ($q) => $q->where('user_id', $user->id)->whereIn('workspace_type', ['personal', 'coach_assigned'])));
    }

    public function detail(User $user, string $key, string $mode, ?int $selected = null, ?User $coach = null): array
    {
        $query = $this->templates($user, $key, $mode);
        if ($coach) {
            $relationship = app(CoachAccess::class)->relationship($coach, $user);
            $query->whereHas('trainingDay.trainingPlan.assignment', fn ($q) => $q->where('coach_trainee_relationship_id', $relationship->id));
        }
        $templates = $query->with('trainingDay.trainingPlan')->orderBy('id')->get();
        if ($selected !== null) {
            abort_unless($templates->contains('id', $selected), 404);
        } elseif ($templates->count() === 1) {
            $selected = $templates->first()->id;
        }
        $template = $templates->firstWhere('id', $selected);
        $suggestion = null;
        if ($template) {
            $suggestion = $this->forTemplate($user, $template, coach: $coach);
        } elseif ($templates->isEmpty()) {
            $recent = $this->recent($user, $key, $mode);
            $prescriptions = $recent->map(fn ($row) => $this->frozenTarget($row));
            // Deleted foreign keys cannot identify which old plan copy was used.
            // Only identical recent frozen prescriptions are unambiguous enough to show.
            if ($recent->isNotEmpty() && $prescriptions->unique(fn ($row) => json_encode($row))->count() === 1 && $this->parsedTarget($prescriptions->first()) !== null) {
                $suggestion = $this->evaluate($prescriptions->first(), $recent);
                $suggestion['historical'] = true;
                $suggestion['can_apply'] = false;
                $suggestion['token'] = null;
            }
        }

        return [
            'templates' => $templates->map(fn ($row) => [
                'id' => $row->id, 'label' => $row->trainingDay->trainingPlan->name.' / '.$row->trainingDay->title.' / '.$row->name,
            ])->values(),
            'selected_id' => $selected, 'suggestion' => $suggestion,
        ];
    }

    private function occurrences(User $user, string $key, string $mode, ?int $templateId = null)
    {
        return SessionExercise::query()->where('source_exercise_key', $key)->where('tracking_mode', $mode)
            ->when($templateId !== null, fn ($q) => $q->where('source_training_exercise_id', $templateId))
            ->where('session_exercises.status', '!=', 'skipped')
            ->join('schedule_entries as session', 'session.id', '=', 'session_exercises.schedule_entry_id')
            ->where('session.user_id', $user->id)->where('session.entry_type', 'training')->where('session.status', 'completed')
            ->select('session_exercises.*', 'session.scheduled_date', 'session.scheduled_time', 'session.updated_at as session_updated_at', 'session.completed_at as session_completed_at')
            ->orderByDesc('session.scheduled_date')->orderByRaw("COALESCE(session.scheduled_time, '00:00:00') DESC")
            ->orderByDesc('session.id')->orderByDesc('session_exercises.id');
    }

    private function recent(User $user, string $key, string $mode, ?int $templateId = null, bool $lock = false): Collection
    {
        // Select occurrences BEFORE inspecting their results: even zero completed sets block an increase.
        return $this->occurrences($user, $key, $mode, $templateId)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->with(['sets' => fn ($q) => $q->when($lock, fn ($q) => $q->lockForUpdate())])
            ->limit(config('training.progression.workouts'))->get();
    }

    public function forTemplate(User $user, TrainingExercise $template, bool $lock = false, ?User $coach = null): array
    {
        $recent = $this->recent($user, $template->library_key, $template->tracking_mode ?? 'reps', $template->id, $lock);
        $result = $this->evaluate([
            'sets' => $template->sets, 'reps' => $template->reps,
            'weight' => $template->target_weight, 'mode' => $template->tracking_mode ?? 'reps',
        ], $recent);
        $result['token'] = hash_hmac('sha256', json_encode([
            $user->id, $template->getAttributes(), config('training.progression'), config('training.limits'),
            $recent->map(fn ($row) => [$row->getAttributes(), $row->sets->map->getAttributes()->all()])->all(),
        ], JSON_THROW_ON_ERROR), config('app.key'));

        if ($coach) {
            $assignment = app(CoachAccess::class)->assignment($coach, $user, $template->trainingDay->trainingPlan, false, $lock);
            $relationship = app(CoachAccess::class)->relationship($coach, $user, $lock);
            $subscription = app(CoachAccess::class)->subscription($relationship, $lock);
            $result['can_apply'] = $result['can_apply'] && $assignment->status === 'active' && $subscription !== null;
            $result['token'] = $result['can_apply'] ? hash_hmac('sha256', json_encode([$result['token'], $coach->id, $assignment->getAttributes(), $relationship->getAttributes(), $subscription->getAttributes()], JSON_THROW_ON_ERROR), config('app.key')) : null;
        }

        return $result;
    }

    private function frozenTarget(SessionExercise $exercise): array
    {
        return ['sets' => $exercise->planned_sets, 'reps' => $exercise->planned_reps_text, 'weight' => $exercise->planned_weight_kg, 'mode' => $exercise->tracking_mode];
    }

    private function parsedTarget(array $target): array|int|null
    {
        if ($target['sets'] < 1 || $target['sets'] > 99 || ($target['mode'] === 'reps' && $target['weight'] > config('training.limits.weight_kg'))) {
            return null;
        }

        return $target['mode'] === 'duration' ? $this->parser->duration($target['reps']) : $this->parser->reps($target['reps']);
    }

    private function evaluate(array $target, Collection $recent): array
    {
        $timed = $target['mode'] === 'duration';
        $weighted = ! $timed && (float) $target['weight'] > 0;
        $parsed = $this->parsedTarget($target);
        $required = (int) $target['sets'];
        $needed = config('training.progression.workouts');
        $current = $weighted ? $this->number($target['weight']).' kg' : ($parsed ? ($timed ? $parsed.' sec' : $this->parser->repText($parsed).' reps') : $target['reps']);
        $base = [
            'type' => 'insufficient_data', 'title' => 'More data needed', 'reason' => '', 'historical' => false,
            'current' => $current, 'target' => $target, 'proposed_value' => null, 'suggested' => null,
            'field' => $weighted ? 'target_weight' : 'reps', 'can_apply' => false,
            'performances' => $recent->count(), 'required_performances' => $needed,
        ];
        $answer = fn ($type, $title, $reason, $extra = []) => [...$base, 'type' => $type, 'title' => $title, 'reason' => $reason, ...$extra];
        if ($parsed === null) {
            return $answer('insufficient_data', 'No automatic suggestion', 'No automatic progression suggestion is available for this prescription. Your plan and workout history remain available.');
        }
        if ($recent->count() < $needed) {
            return $answer('insufficient_data', 'More data needed', "{$recent->count()} of $needed performances recorded for this plan exercise. Complete another detailed workout before a progression suggestion is available.");
        }
        $results = $recent->map(function ($exercise) use ($timed, $weighted, $target, $parsed) {
            $valid = $exercise->sets->filter(fn ($set) => $set->completed_at !== null && ($timed ? $set->actual_duration_seconds > 0 : $set->actual_reps > 0));
            $atWeight = $valid->filter(fn ($set) => ! $weighted || ($set->actual_weight_kg > 0 && $set->actual_weight_kg >= $target['weight']));

            return [
                'valid' => $valid->count(), 'at_weight' => $atWeight->count(),
                'minimum' => $valid->filter(fn ($set) => $timed ? $set->actual_duration_seconds >= $parsed : $set->actual_reps >= $parsed['min'])->count(),
                'upper' => $atWeight->filter(fn ($set) => $timed ? $set->actual_duration_seconds >= $parsed : $set->actual_reps >= $parsed['max'])->count(),
            ];
        });
        if ($results->contains(fn ($row) => $row['valid'] < $required)) {
            return $answer('build_reps', $timed ? 'Complete planned sets' : 'Build reps first', "Complete all $required planned working sets in each of your latest $needed workouts before increasing the target.");
        }
        if (! $timed && $results->contains(fn ($row) => $row['minimum'] < $required)) {
            return $answer('build_reps', 'Build reps first', "Build at least $required completed working sets to {$parsed['min']} reps or more in each of your latest $needed workouts.");
        }
        if ($weighted && $results->contains(fn ($row) => $row['at_weight'] < $required)) {
            return $answer('hold_weight', 'Keep current planned weight', "Recent working sets were below the planned weight or had no recorded weight. Complete $required sets at or above $current in each workout before increasing.");
        }
        if ($results->contains(fn ($row) => $row['upper'] < $required)) {
            return $answer('hold_weight', $weighted ? 'Keep current planned weight' : 'Keep current target', $timed
                ? "Reach $current across at least $required completed sets in each of your latest $needed workouts before increasing duration."
                : "You are working within the target rep range. Reach {$parsed['max']} reps across at least $required completed sets in each of your latest $needed workouts before increasing.");
        }
        if ($weighted) {
            $next = round((float) $target['weight'] + config('training.progression.weight_increment'), 2);
            $overflow = $next > config('training.limits.weight_kg');
            $suggested = $this->number($next).' kg';
            $reason = "You completed at least $required sets of {$parsed['max']} reps at or above $current in your latest $needed workouts.";
        } elseif ($timed) {
            $next = $parsed + config('training.progression.duration_increment');
            $overflow = $next > config('training.limits.duration_seconds');
            $next = $next.' sec';
            $suggested = $next;
            $reason = "You completed at least $required sets of $current or longer in your latest $needed workouts.";
        } else {
            $range = ['min' => $parsed['min'] + config('training.progression.rep_increment'), 'max' => $parsed['max'] + config('training.progression.rep_increment')];
            $overflow = $range['max'] > config('training.limits.reps');
            $next = $this->parser->repText($range);
            $suggested = $next.' reps';
            $reason = "You completed at least $required sets of {$parsed['max']} reps or more in your latest $needed workouts.";
        }
        if ($overflow) {
            return $answer('hold_weight', 'Keep current target', 'The next increase exceeds the supported limit. Keep your current target.');
        }

        return $answer($weighted ? 'increase_weight' : ($timed ? 'increase_duration' : 'increase_reps'),
            $weighted ? 'Increase planned weight' : ($timed ? 'Increase duration target' : 'Increase reps target'),
            $reason, ['proposed_value' => (string) $next, 'suggested' => $suggested, 'can_apply' => true]);
    }

    private function number($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    public function apply(User $user, TrainingExercise $template, string $token, mixed $value): void
    {
        Gate::forUser($user)->authorize('update', $template->trainingDay);
        abort_if($template->trainingDay->trainingPlan->workspace_type === 'coach_template', 403);
        $this->applyTarget($user, $template, $token, $value);
    }

    public function applyForCoach(User $coach, User $trainee, TrainingExercise $template, string $token, mixed $value): void
    {
        app(CoachAccess::class)->transaction($coach, $trainee, fn () => $this->applyTarget($trainee, $template, $token, $value, $coach));
    }

    private function applyTarget(User $user, TrainingExercise $template, string $token, mixed $value, ?User $coach = null): void
    {
        $authorize = function ($day) use ($user, $coach) {
            if ($coach) {
                app(CoachAccess::class)->assignment($coach, $user, $day->trainingPlan, true, true);
            } else {
                Gate::forUser($user)->authorize('update', $day);
            }
        };
        $authorize($template->trainingDay);
        DB::transaction(function () use ($user, $template, $token, $value, $coach, $authorize) {
            // Share the start-workout lock, and the day-editor lock, without rewriting either workflow.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $day = TrainingDay::lockForUpdate()->findOrFail($template->training_day_id);
            $authorize($day);
            $template = TrainingExercise::where('training_day_id', $day->id)->lockForUpdate()->findOrFail($template->id);
            if (! $template->library_key || $day->type !== 'training') {
                throw ValidationException::withMessages(['progression' => 'This plan exercise has changed. Refresh and choose a current training exercise.']);
            }
            // Logging, completion, reopening and deletion already lock these parent sessions.
            // Include planned/skipped sessions so a concurrent recompletion cannot slip into the evidence.
            ScheduleEntry::where('user_id', $user->id)->whereHas('sessionExercises', fn ($q) => $q
                ->where('source_exercise_key', $template->library_key)->where('source_training_exercise_id', $template->id))
                ->orderBy('id')->lockForUpdate()->get(['id']);
            // Locking reads see the latest committed evidence under MySQL REPEATABLE READ,
            // even if the ownership lookup established an earlier transaction snapshot.
            $suggestion = $this->forTemplate($user, $template, lock: true, coach: $coach);
            if (! hash_equals($suggestion['token'], $token) || ! $suggestion['can_apply']) {
                throw ValidationException::withMessages(['progression' => 'Your plan or workout results changed. Review the refreshed suggestion before applying.']);
            }
            if ($suggestion['field'] === 'target_weight') {
                Validator::make(['value' => $value], ['value' => 'required|numeric|decimal:0,2|gt:0|max:'.config('training.limits.weight_kg')])->validate();
                $value = round((float) $value, 2);
            } else {
                Validator::make(['value' => $value], ['value' => 'required|string|max:100'])->validate();
                $parsed = $template->tracking_mode === 'duration' ? $this->parser->duration($value) : $this->parser->reps($value);
                if ($parsed === null) {
                    throw ValidationException::withMessages(['value' => 'Enter a supported positive target within the allowed limit.']);
                }
                $value = $template->tracking_mode === 'duration' ? $parsed.' sec' : $this->parser->repText($parsed);
            }
            // Assign a value, never increment on submission. The fingerprint rejects repeats/stale forms.
            $template->update([$suggestion['field'] => $value]);
        }, 3);
    }
}
