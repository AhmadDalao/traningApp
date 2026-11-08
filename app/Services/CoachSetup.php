<?php

namespace App\Services;

use App\Models\CoachSubscriptionPlan;
use App\Models\CoachTraineeRelationship;
use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\Notifications\CoachNotifications;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Coordinates one draft/activation transaction; existing services own training and subscriptions. */
class CoachSetup
{
    public function defaults(CoachTraineeRelationship $relationship): array
    {
        $assignment = $relationship->assignments()->where('status', 'active')->whereHas('plan')->with('plan')->latest('id')->first();
        $subscription = $relationship->subscriptions()->latest('id')->first();

        return ['selection_type' => $assignment ? 'assigned' : 'template', 'training_plan_id' => $assignment?->assigned_training_plan_id,
            'start_date' => max(today()->toDateString(), $assignment?->plan?->start_date?->toDateString() ?? today()->toDateString()),
            'schedule_mode' => $assignment?->plan?->schedule_mode ?? 'fixed', 'end_date' => $assignment?->plan?->end_date?->toDateString(),
            'day_options' => [], 'time_overrides' => [], 'package_id' => null, 'package_name' => $subscription?->package_name ?? 'Coaching',
            'open_ended' => false, 'coaching_ends_on' => null, 'include_extension' => false];
    }

    public function publicDraft(CoachTraineeRelationship $relationship): array
    {
        $draft = $relationship->setup_draft;

        return ['revision' => $draft['revision'] ?? 0, 'token' => $draft['token'] ?? null,
            'choices' => $draft['choices'] ?? $this->defaults($relationship), 'completed' => isset($draft['result']), 'saved_at' => $draft['saved_at'] ?? null];
    }

    public function save(User $coach, User $trainee, array $input): array
    {
        $data = Validator::make($input, [
            'revision' => 'required|integer|min:0', 'begin' => 'sometimes|boolean',
            'choices' => 'required|array:selection_type,training_plan_id,start_date,schedule_mode,end_date,day_options,time_overrides,package_id,package_name,open_ended,coaching_ends_on,include_extension',
            'choices.selection_type' => 'required|in:template,assigned', 'choices.training_plan_id' => 'nullable|integer|min:1',
            'choices.start_date' => 'nullable|date_format:Y-m-d', 'choices.schedule_mode' => 'required|in:fixed,ongoing',
            'choices.end_date' => 'nullable|date_format:Y-m-d', 'choices.day_options' => 'present|array|max:364',
            'choices.day_options.*' => 'array:weekdays,time', 'choices.day_options.*.weekdays' => 'required|array|max:7',
            'choices.day_options.*.weekdays.*' => 'integer|between:0,6', 'choices.day_options.*.time' => 'present|nullable|date_format:H:i',
            'choices.time_overrides' => 'present|array|max:1000', 'choices.time_overrides.*' => 'nullable|date_format:H:i',
            'choices.package_id' => 'nullable|integer|min:1', 'choices.package_name' => 'required|string|max:100',
            'choices.open_ended' => 'required|boolean', 'choices.coaching_ends_on' => 'nullable|date_format:Y-m-d', 'choices.include_extension' => 'required|boolean',
        ])->validate();
        abort_if(strlen(json_encode($data['choices'])) > 65536, 422, 'Keep setup options below 64 KB.');

        return app(CoachAccess::class)->transaction($coach, $trainee, function ($relationship) use ($data) {
            $old = $relationship->setup_draft;
            abort_unless(($old['revision'] ?? 0) === $data['revision'], 409, 'Setup changed in another tab. Reload to review the latest choices.');
            if (isset($old['result'])) {
                $current = app(CoachingSubscriptions::class)->setupReview($relationship, [], null);
                $newTraining = $data['choices']['selection_type'] !== $old['choices']['selection_type']
                    || (int) $data['choices']['training_plan_id'] !== (int) $old['choices']['training_plan_id'];
                abort_unless(($data['begin'] ?? false) && ($current['operation'] !== 'reuse' || $newTraining), 409, 'This training is already assigned. View its training or adjust its existing schedule.');
                $old = null;
            }
            $choices = $data['choices'];
            // Validate resource scope now, and again during preview/activation after resource changes.
            if (! empty($choices['training_plan_id'])) {
                $this->selection($relationship, $choices, true);
            }
            if (! empty($choices['package_id'])) {
                abort_unless(CoachSubscriptionPlan::where('coach_user_id', $relationship->coach_user_id)->where('active', true)->whereKey($choices['package_id'])->exists(), 404);
            }
            foreach (array_keys($choices['day_options']) as $key) {
                abort_unless(preg_match('/^[1-9][0-9]*$/', (string) $key), 422);
            }
            foreach (array_keys($choices['time_overrides']) as $key) {
                abort_unless(preg_match('/^[1-9][0-9]*:\d{4}-\d{2}-\d{2}$/', (string) $key), 422);
            }
            if ($old && $old['choices'] == $choices) {
                return $this->publicDraft($relationship);
            }
            $relationship->setup_draft = ['version' => 1, 'revision' => $data['revision'] + 1, 'token' => $old['token'] ?? (string) Str::uuid(),
                'choices' => $choices, 'saved_at' => now()->toIso8601String()];
            $relationship->save();

            return $this->publicDraft($relationship);
        }, false);
    }

    private function selection(CoachTraineeRelationship $relationship, array $choices, bool $required = false): array
    {
        $id = $choices['training_plan_id'] ?? null;
        $assignment = null;
        if (($choices['selection_type'] ?? 'template') === 'assigned') {
            $assignment = $relationship->assignments()->where('assigned_training_plan_id', $id)->where('status', 'active')->lockForUpdate()->first();
            $selected = $assignment ? TrainingPlan::where('user_id', $relationship->trainee_user_id)->where('workspace_type', 'coach_assigned')->lockForUpdate()->find($id) : null;
        } else {
            $selected = TrainingPlan::where('user_id', $relationship->coach_user_id)->where('workspace_type', 'coach_template')->lockForUpdate()->find($id);
            if ($selected) {
                $assignment = $relationship->assignments()->where('source_training_plan_id', $selected->id)->where('status', 'active')->whereHas('plan')->latest('id')->lockForUpdate()->first();
            }
        }
        if ($required) {
            abort_unless($selected, 404);
        }
        $plan = $assignment ? TrainingPlan::where('user_id', $relationship->trainee_user_id)->where('workspace_type', 'coach_assigned')->lockForUpdate()->find($assignment->assigned_training_plan_id) : $selected;

        return [$selected, $assignment, $plan];
    }

    public function preview(User $coach, User $trainee, int $revision, string $token): array
    {
        return app(CoachAccess::class)->transaction($coach, $trainee, fn ($relationship) => $this->review($relationship, $revision, $token), false);
    }

    private function review(CoachTraineeRelationship $relationship, int $revision, string $token): array
    {
        $draft = $relationship->setup_draft;
        abort_unless($draft && $draft['revision'] === $revision && hash_equals($draft['token'], $token), 409, 'Setup changed. Save and review the latest choices.');
        abort_if(isset($draft['result']), 409, 'Setup already completed. Return to the trainee overview.');
        $choices = $draft['choices'];
        [$selected, $assignment, $plan] = $this->selection($relationship, $choices);
        $issues = [];
        $rows = [];
        $definition = null;
        $run = null;
        $hasSchedule = false;
        $existing = collect();
        $days = collect();
        $end = null;
        $start = $choices['start_date'] ?? null;
        if (! $plan) {
            $issues[] = ['code' => 'training_unavailable', 'field' => 'training_plan_id', 'message' => 'Selected Training Program is no longer available. Choose training again.'];
        } else {
            $days = $plan->trainingDays()->with('exercises')->lockForUpdate()->get();
            if ($assignment) {
                $run = $plan->programRuns()->latest('id')->lockForUpdate()->first();
                $existing = ScheduleEntry::where('user_id', $relationship->trainee_user_id)->where(fn ($q) => $q->whereIn('training_day_id', $days->modelKeys())->when($run, fn ($q) => $q->orWhere('program_run_id', $run->id)))
                    ->withExists('sessionExercises')->orderBy('scheduled_date')->orderBy('id')->lockForUpdate()->get();
            }
            foreach (array_keys($choices['day_options']) as $id) {
                if (! $days->contains('id', (int) $id)) {
                    $issues[] = ['code' => 'day_unavailable', 'field' => 'day_options', 'message' => 'A workout changed. Review the current Training Program before adjusting its days.'];
                }
            }
            $hasSchedule = (bool) $run || $existing->isNotEmpty() || ($assignment && $plan->schedule_actor_id !== null);
            if ($hasSchedule) {
                $start = $run?->started_on?->toDateString() ?? $plan->start_date?->toDateString();
                $end = $run ? $run->started_on->copy()->addDays($run->definition_snapshot['duration_weeks'] * 7 - 1)->toDateString() : $plan->end_date?->toDateString();
                $rows = $existing->map(fn ($e) => [...$e->only('id', 'training_day_id', 'title', 'entry_type', 'scheduled_time', 'status', 'program_week', 'program_context'),
                    'scheduled_date' => $e->scheduled_date->toDateString(), 'key' => 'existing:'.$e->id, 'reused' => true])->all();
                if ($choices['day_options'] || $choices['time_overrides']) {
                    $issues[] = ['code' => 'schedule_active', 'field' => 'day_options', 'message' => 'Schedule already active. Clear setup adjustments; use the existing schedule controls to change it.'];
                }
            } else {
                if (! $start || $start < today()->toDateString()) {
                    $issues[] = ['code' => 'start_date', 'field' => 'start_date', 'message' => 'Choose a Training Start Date today or later.'];
                }
                if ($start) {
                    try {
                        if ($plan->isProgram()) {
                            $definition = app(ProgramDefinition::class)->snapshot($plan);
                            foreach ($definition['blocks'] as &$block) {
                                foreach ($block['days'] as &$day) {
                                    if ($option = $choices['day_options'][$day['id']] ?? null) {
                                        $day['weekdays'] = $option['weekdays'];
                                        $day['default_time'] = $option['time'];
                                    }
                                }
                            }
                            unset($block, $day);
                            $rows = app(ProgramSchedule::class)->desired($plan, $start, 'setup', $definition, $choices['time_overrides'])['sessions'];
                            $end = Carbon::parse($start)->addDays($plan->duration_weeks * 7 - 1)->toDateString();
                        } else {
                            $working = clone $plan;
                            $working->fill(['start_date' => $start, 'end_date' => $choices['schedule_mode'] === 'ongoing' ? null : $choices['end_date'], 'schedule_mode' => $choices['schedule_mode']]);
                            $working->setRelation('trainingDays', $days->map(function ($day) use ($choices, $assignment) {
                                $copy = clone $day;
                                if (! $assignment || isset($choices['day_options'][$day->id])) {
                                    $copy->forceFill(['schedule_rules' => null]);
                                }
                                if ($option = $choices['day_options'][$day->id] ?? null) {
                                    $copy->fill(['weekdays' => $option['weekdays'], 'default_time' => $option['time'], 'schedule_rules' => null]);
                                }

                                return $copy;
                            }));
                            $end = $working->schedule_mode === 'ongoing' ? app(PlanSchedule::class)->horizon() : $working->end_date?->toDateString();
                            if (! $end || $end < $start) {
                                $issues[] = ['code' => 'end_date', 'field' => 'end_date', 'message' => 'Choose a Training End Date on or after the start date.'];
                            }
                            $rows = app(PlanSchedule::class)->desired($working, true, $choices['time_overrides']);
                        }
                    } catch (ValidationException $error) {
                        foreach ($error->errors() as $field => $messages) {
                            foreach ($messages as $message) {
                                $issues[] = ['code' => 'training_definition', 'field' => $field, 'message' => $message];
                            }
                        }
                    }
                }
                if (! $rows) {
                    $issues[] = ['code' => 'empty_schedule', 'field' => 'start_date', 'message' => 'No workouts match these dates. Select weekdays and a start inside the scheduling window.'];
                }
                $keys = array_map(fn ($row) => $row['training_day_id'].':'.$row['scheduled_date'], $rows);
                foreach (array_keys($choices['time_overrides']) as $key) {
                    if (! in_array($key, $keys, true)) {
                        $issues[] = ['code' => 'override_unavailable', 'field' => 'time_overrides', 'message' => 'A corrected workout no longer matches this schedule. Clear its correction and review again.'];
                    }
                }
            }
        }
        $coverageDates = array_filter([$end, ...array_column($rows, 'scheduled_date')]);
        $required = $coverageDates ? max($coverageDates) : null;
        $subscription = app(CoachingSubscriptions::class)->setupReview($relationship, $choices, $required);
        array_push($issues, ...$subscription['issues']);
        foreach ($rows as &$row) {
            $row['issues'] = [];
            if (! ($row['reused'] ?? false) && app(WorkoutDate::class)->elapsed($row['scheduled_date'], $row['scheduled_time'])) {
                $row['issues'][] = 'This workout date or time has already passed. Change the time, choose Anytime today, or adjust the training start.';
            }
            // Reusing history never requires retroactively extending the new subscription backwards.
            if (! ($row['reused'] ?? false) && ($row['scheduled_date'] < $subscription['starts_on'] || ($subscription['ends_on'] && $row['scheduled_date'] > $subscription['ends_on']))) {
                $row['issues'][] = 'Outside Coaching Subscription coverage. Include an extension through '.$required.'.';
            }
            foreach ($row['issues'] as $message) {
                $issues[] = ['code' => 'schedule_row', 'key' => $row['key'], 'field' => 'time_overrides', 'title' => $row['title'], 'date' => $row['scheduled_date'], 'time' => $row['scheduled_time'], 'message' => $message];
            }
        }
        unset($row);
        $preview = ['trainee' => $relationship->trainee->only('name', 'training_code'), 'training' => $plan?->only('id', 'name', 'structure_type', 'duration_weeks'),
            'start_date' => $start, 'ends_on' => $end, 'subscription' => collect($subscription)->except('fingerprint')->all(),
            'sessions' => app(ProgramSchedule::class)->publicRows($rows), 'count' => count($rows), 'issues' => $issues,
            'already_scheduled' => $hasSchedule, 'assignment_reused' => (bool) $assignment,
            'revision' => $revision, 'valid' => ! $issues,
            'days' => $days->map(fn ($d) => [...$d->only('id', 'title', 'type', 'weekdays'), 'thumbnail_url' => $d->thumbnail_url, 'time' => $d->default_time ? substr($d->default_time, 0, 5) : null])->all()];
        $preview['signature'] = hash_hmac('sha256', json_encode([$relationship->id, $relationship->coach_user_id, $relationship->trainee_user_id, $relationship->status,
            today()->toDateString(), $draft, $selected?->getAttributes(), $plan?->getAttributes(), $assignment?->getAttributes(), $run?->getAttributes(),
            $definition, $days->map(fn ($day) => [$day->getAttributes(), $day->exercises->map->getAttributes()->all()])->all(),
            $existing->map->getAttributes()->all(), $subscription['fingerprint'], $preview], JSON_THROW_ON_ERROR), config('app.key'));

        return $preview;
    }

    public function activate(User $coach, User $trainee, array $input): array
    {
        $paths = [];
        try {
            return app(CoachAccess::class)->transaction($coach, $trainee, function ($relationship) use ($coach, $trainee, $input, &$paths) {
                $draft = $relationship->setup_draft;
                abort_unless($draft && hash_equals($draft['token'], $input['token']), 409, 'This setup token is no longer current. Reload setup.');
                if (isset($draft['result'])) {
                    return $draft['result'];
                }
                $preview = $this->review($relationship, $input['revision'], $input['token']);
                if (! hash_equals($preview['signature'], $input['signature']) || ! $preview['valid']) {
                    throw ValidationException::withMessages(['preview' => 'Setup, dates, or schedule changed. Review a fresh preview before confirming.']);
                }
                $choices = $draft['choices'];
                [$source, $assignment, $plan] = $this->selection($relationship, $choices, true);
                $subscription = app(CoachingSubscriptions::class)->applySetup($coach, $trainee, $relationship, $preview['subscription']);
                $map = [];
                if (! $assignment) {
                    $plan = app(TrainingPlanDuplicator::class)->assign($coach, $trainee, $source,
                        ['start_date' => $choices['start_date'], 'end_date' => $choices['schedule_mode'] === 'ongoing' ? null : $choices['end_date'], 'schedule_mode' => $choices['schedule_mode']],
                        false, false, $paths, $map);
                    $assignment = $plan->assignment;
                } else {
                    foreach ($plan->trainingDays()->get() as $day) {
                        $map[$day->id] = $day->id;
                    }
                }
                $run = $plan->programRuns()->latest('id')->lockForUpdate()->first();
                $added = 0;
                if (! $preview['already_scheduled']) {
                    foreach ($choices['day_options'] as $id => $option) {
                        $plan->trainingDays()->whereKey($map[$id])->update(['weekdays' => json_encode($option['weekdays']), 'default_time' => $option['time'], 'schedule_rules' => null]);
                    }
                    $times = [];
                    foreach ($choices['time_overrides'] as $key => $time) {
                        [$id, $date] = explode(':', $key, 2);
                        $times[$map[$id].':'.$date] = $time;
                    }
                    if ($plan->isProgram()) {
                        $data = ['start_date' => $choices['start_date'], 'nonce' => $draft['token']];
                        $data['signature'] = app(ProgramSchedule::class)->preview($plan, $data['start_date'], $data['nonce'], $coach, $times)['signature'];
                        $run = app(ProgramSchedule::class)->start($plan, $data, $coach, $paths, false, $times);
                        $added = $run->sessions()->count();
                    } else {
                        $plan->fill(['start_date' => $choices['start_date'], 'end_date' => $choices['schedule_mode'] === 'ongoing' ? null : $choices['end_date'], 'schedule_mode' => $choices['schedule_mode']])->save();
                        $plan->unsetRelation('trainingDays');
                        $schedule = app(PlanSchedule::class)->preview($plan, coach: $coach, timeOverrides: $times);
                        $added = app(PlanSchedule::class)->apply($plan, $schedule['signature'], 'add', false, $coach, false, $times)['added'];
                    }
                    if ($added !== $preview['count']) {
                        throw new \RuntimeException('Activation schedule did not match the reviewed occurrence count.');
                    }
                }
                $operation = $preview['subscription']['operation'];
                $message = match ($operation) {
                    'resume' => 'Coaching resumed today.', 'renew' => 'Coaching renewed'.($subscription->ends_on ? ' through '.$subscription->ends_on->toDateString() : ' with ongoing coverage').'.',
                    'reuse' => 'Coaching setup complete.', default => 'Coaching activated today.'
                };
                $message .= ' '.$plan->name.($added ? ' starts '.$preview['start_date'].'. '.$added.' sessions scheduled.' : '. Existing schedule preserved.');
                $result = ['url' => '/coach/trainees/'.$trainee->id, 'message' => $message, 'subscription_id' => $subscription->id,
                    'assignment_id' => $assignment->id, 'program_run_id' => $run?->id, 'added' => $added, 'activated_at' => now()->toIso8601String()];
                $relationship->setup_draft = [...$draft, 'result' => $result];
                $relationship->save();
                app(CoachNotifications::class)->plan($coach, $plan, 'setup', $draft['token']);

                return $result;
            }, false);
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($paths);
            throw $error;
        }
    }
}
