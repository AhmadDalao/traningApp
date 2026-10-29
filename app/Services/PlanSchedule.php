<?php

namespace App\Services;

use App\Models\ScheduleEntry;
use App\Models\TrainingDay;
use App\Models\TrainingPlan;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PlanSchedule
{
    public function preview(TrainingPlan $plan, bool $lock = false, ?User $coach = null, array $change = [], bool $rolling = false): array
    {
        $plan->loadMissing('trainingDays');
        $assignment = $coach ? app(CoachAccess::class)->assignment($coach, $plan->user, $plan, true, $lock) : null;
        $subscription = $assignment ? app(CoachAccess::class)->subscription($assignment->relationship, $lock) : null;
        $desired = $this->desired($plan);
        $keys = array_column($desired, 'key');
        $entries = ScheduleEntry::where('user_id', $plan->user_id)
            ->where(fn ($q) => $q->whereIn('training_day_id', $plan->trainingDays->modelKeys())
                ->orWhere('plan_occurrence_key', 'like', 'plan:'.$plan->id.':%'))
            // Rolling refresh needs window bookings and their moved originals, not full history.
            ->when($rolling, fn ($q) => $q->where(fn ($q) => $q
                ->whereBetween('scheduled_date', [today()->toDateString(), $this->horizon()])
                ->orWhereIn('plan_occurrence_key', $keys)))
            ->select('id', 'training_day_id', 'title', 'entry_type', 'scheduled_date', 'scheduled_time', 'status', 'started_at', 'plan_occurrence_key', 'schedule_managed', 'updated_at', 'scheduled_by_user_id')
            ->selectRaw('workout_snapshot IS NOT NULL AS has_snapshot')->withExists('sessionExercises')
            ->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $originalEntries = $entries->map->getAttributes()->all();
        $cancelled = DB::table('schedule_occurrence_cancellations')->where('training_plan_id', $plan->id)
            ->when($rolling, fn ($q) => $q->whereIn('occurrence_key', $keys))
            ->orderBy('occurrence_key')->when($lock, fn ($q) => $q->lockForUpdate())->pluck('occurrence_key')->all();
        if (isset($change['entry'])) {
            $moved = $entries->firstWhere('id', $change['entry']);
            $moved?->forceFill(['scheduled_date' => $change['date'], 'scheduled_time' => $change['time'], 'schedule_managed' => false]);
        }
        $eligible = [];
        $protected = [];
        foreach ($entries as $entry) {
            $reason = $this->protection($entry, $plan);
            if ($coach && ! $reason) {
                if ($entry->scheduled_by_user_id !== $coach->id || ! $plan->trainingDays->contains('id', $entry->training_day_id)) {
                    $reason = 'Personal, other-coach or detached session';
                } elseif ($entry->scheduled_date->lt($subscription->starts_on) || ($subscription->ends_on && $entry->scheduled_date->gt($subscription->ends_on))) {
                    $reason = 'Outside the active subscription dates';
                }
            }
            $row = [...$entry->only('id', 'title', 'entry_type', 'status', 'scheduled_time'), 'scheduled_date' => $entry->scheduled_date->toDateString(), 'reason' => $reason];
            if ($reason) {
                $protected[] = $row;
            } else {
                $eligible[$entry->id] = $row;
            }
        }
        $occurrences = $entries->whereNotNull('plan_occurrence_key')->keyBy('plan_occurrence_key');
        $bookings = $entries->keyBy(fn ($entry) => $entry->training_day_id.':'.$entry->scheduled_date->toDateString());
        $sessions = [];
        $excluded = [];
        $add = [];
        $keep = [];
        foreach ($desired as $session) {
            if (in_array($session['key'], $cancelled, true)) {
                continue;
            }
            if ($subscription && ($session['scheduled_date'] < $subscription->starts_on->toDateString() || ($subscription->ends_on && $session['scheduled_date'] > $subscription->ends_on->toDateString()))) {
                $excluded[] = [...$session, 'reason' => 'Outside the active subscription dates'];

                continue;
            }
            // The original occurrence key survives a manual move and still prevents a duplicate.
            $booked = $occurrences->get($session['key']) ?? $bookings->get($session['training_day_id'].':'.$session['scheduled_date']);
            $session['existing'] = $booked !== null;
            $session['existing_date'] = $booked && ! $booked->scheduled_date->isSameDay($session['scheduled_date']) ? $booked->scheduled_date->toDateString() : null;
            $replace = $booked && isset($eligible[$booked->id]) &&
                ($this->time($booked->scheduled_time) !== $this->time($session['scheduled_time']) || $booked->entry_type !== $session['entry_type']);
            $session['replaces_id'] = $replace ? $booked->id : null;
            if (! $booked || $replace) {
                $add[] = $session;
            } elseif (isset($eligible[$booked->id])) {
                $keep[$booked->id] = $eligible[$booked->id];
            }
            $sessions[] = $session;
        }
        foreach ($eligible as $id => $row) {
            $entry = $entries->firstWhere('id', $id);
            if (($plan->schedule_mode === 'ongoing' && $row['scheduled_date'] > $this->horizon()) ||
                (isset($change['day']) && ($entry->training_day_id !== $change['day'] || $row['scheduled_date'] < $change['from']))) {
                $keep[$id] = $row;
            }
        }
        if (isset($change['day'])) {
            $add = array_values(array_filter($add, fn ($row) => $row['training_day_id'] === $change['day'] && $row['scheduled_date'] >= $change['from']));
        }
        $remove = array_values(array_diff_key($eligible, $keep));
        $result = [
            'excluded' => $excluded,
            'sessions' => $sessions, 'add' => $add, 'keep' => array_values($keep), 'remove' => $remove, 'protected' => $protected,
            'eligible' => array_values($eligible),
            'new_count' => count(array_filter($add, fn ($row) => ! $row['replaces_id'])),
            'existing_count' => count(array_filter($sessions, fn ($row) => $row['existing'])),
            'replacement_count' => count(array_filter($add, fn ($row) => $row['replaces_id'])),
            'horizon' => $plan->schedule_mode === 'ongoing' ? $this->horizon() : null,
            'message' => ! $plan->start_date || ($plan->schedule_mode !== 'ongoing' && ! $plan->end_date) ? 'Add a start and end date to this plan to generate sessions.' : (count($sessions) ? null : 'No future dates match this plan. Check the period, weekdays and usual times.'),
        ];
        $result['signature'] = hash_hmac('sha256', json_encode([
            $coach?->id, $assignment?->getAttributes(), $assignment?->relationship->getAttributes(), $subscription?->getAttributes(),
            $plan->getAttributes(), $cancelled, $change,
            $plan->trainingDays->map->getAttributes()->all(), $originalEntries, $result,
        ], JSON_THROW_ON_ERROR), config('app.key'));

        return $result;
    }

    private function desired(TrainingPlan $plan): array
    {
        if (! $plan->start_date || ($plan->schedule_mode !== 'ongoing' && ! $plan->end_date)) {
            return [];
        }
        if ($plan->schedule_mode !== 'ongoing' && $plan->start_date->diffInDays($plan->end_date) > 365) {
            throw ValidationException::withMessages(['schedule' => 'Preview a plan period of up to one year. Shorten the plan dates to continue.']);
        }
        $start = $plan->start_date->copy()->max(today());
        $end = $plan->schedule_mode === 'ongoing' ? Carbon::parse($this->horizon())->min($plan->end_date ?? Carbon::parse($this->horizon())) : $plan->end_date;
        $sessions = [];
        if ($start->lte($end)) {
            foreach (CarbonPeriod::create($start, $end) as $date) {
                foreach ($plan->trainingDays as $day) {
                    if (! in_array($date->dayOfWeek, $day->weekdaysOn($date->toDateString()), false) || ! $this->future($date->toDateString(), $day->default_time)) {
                        continue;
                    }
                    $sessions[] = [
                        'key' => 'plan:'.$plan->id.':'.$day->id.':'.$date->toDateString(), 'training_day_id' => $day->id,
                        'title' => $day->title, 'entry_type' => $day->type, 'focus_area' => $day->focus_area,
                        'icon' => $day->icon, 'scheduled_date' => $date->toDateString(), 'scheduled_time' => $day->default_time, 'notes' => $day->notes,
                    ];
                    if (count($sessions) > 1000) {
                        throw ValidationException::withMessages(['schedule' => 'This preview exceeds 1,000 sessions. Shorten the plan dates to continue.']);
                    }
                }
            }
        }

        return $sessions;
    }

    private function time(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }

    private function future(string $date, ?string $time): bool
    {
        // Match History: today's untimed and already-started times are historical.
        return $date > today()->toDateString() || ($date === today()->toDateString() && $time !== null && substr($time, 0, 5) > now()->format('H:i'));
    }

    private function protection(ScheduleEntry $entry, TrainingPlan $plan): ?string
    {
        if ($entry->status !== 'planned') {
            return ucfirst($entry->status).' session';
        }
        if ($entry->started_at !== null) {
            return 'Workout already started';
        }
        if ($entry->session_exercises_exists || $entry->has_snapshot) {
            return 'Saved workout details';
        }
        if (! $this->future($entry->scheduled_date->toDateString(), $entry->scheduled_time)) {
            return 'Historical session';
        }
        if (! preg_match('/^plan:'.$plan->id.':([1-9][0-9]*):(\d{4}-\d{2}-\d{2})$/D', $entry->plan_occurrence_key ?? '', $match)) {
            return 'Manually scheduled';
        }
        if (! $entry->schedule_managed) {
            return 'Kept independently (earlier session or individual Calendar edit)';
        }
        if ($entry->scheduled_date->toDateString() !== $match[2] || ($entry->training_day_id !== null && (int) $entry->training_day_id !== (int) $match[1])) {
            return 'Moved individually in Calendar';
        }

        return null;
    }

    public function apply(TrainingPlan $plan, string $signature, string $action, bool $removeObsolete, ?User $coach = null): array
    {
        // Called inside a transaction holding the user, plan and training-day locks.
        $preview = $this->preview($plan, lock: true, coach: $coach);
        if (! hash_equals($preview['signature'], $signature)) {
            throw ValidationException::withMessages(['schedule' => 'The plan or Calendar changed. Review the refreshed preview before confirming.']);
        }
        $remove = $action === 'remove' ? $preview['eligible'] : ($action === 'regenerate' && $removeObsolete ? $preview['remove'] : []);
        $ids = array_column($remove, 'id');
        if ($ids) {
            // Parent-session locks also serialize workout start/log/status/update/delete.
            ScheduleEntry::where('user_id', $plan->user_id)->whereIn('id', $ids)->delete();
        }
        $added = 0;
        if ($action !== 'remove') {
            foreach ($preview['add'] as $session) {
                if ($session['replaces_id'] && ! in_array($session['replaces_id'], $ids, true)) {
                    continue;
                }
                $entry = $plan->user->scheduleEntries()->firstOrCreate(['plan_occurrence_key' => $session['key']], [
                    ...collect($session)->except('key', 'existing', 'existing_date', 'replaces_id')->all(),
                    'status' => 'planned', 'schedule_managed' => true, 'scheduled_by_user_id' => $coach?->id ?? $plan->user_id,
                ]);
                $added += $entry->wasRecentlyCreated ? 1 : 0;
            }
        }

        $plan->forceFill(['schedule_enabled' => $action !== 'remove' && $plan->schedule_mode === 'ongoing', 'schedule_actor_id' => $coach?->id ?? $plan->user_id])->save();

        return ['added' => $added, 'removed' => count($ids)];
    }

    public function horizon(): string
    {
        return today()->addDays(config('training.schedule_horizon_days'))->toDateString();
    }

    public function canMove(ScheduleEntry $entry, User $owner, ?User $coach = null): bool
    {
        if ($entry->user_id !== $owner->id || $entry->status !== 'planned' || $entry->started_at || $entry->workout_snapshot !== null ||
            ($entry->session_exercises_exists ?? $entry->sessionExercises()->exists()) || ! $this->future($entry->scheduled_date->toDateString(), $entry->scheduled_time)) {
            return false;
        }

        return ! $coach || app(CoachAccess::class)->canManageSession($coach, $owner, $entry);
    }

    public function canMoveSeries(ScheduleEntry $entry): bool
    {
        $day = $entry->trainingDay;
        $plan = $day?->trainingPlan;

        return $plan && $plan->start_date && ($plan->schedule_mode === 'ongoing' || $plan->end_date) && $entry->schedule_managed &&
            $entry->plan_occurrence_key === 'plan:'.$plan->id.':'.$day->id.':'.$entry->scheduled_date->toDateString() &&
            in_array($entry->scheduled_date->dayOfWeek, $day->weekdaysOn($entry->scheduled_date->toDateString()));
    }

    /** Explicit weekday phases, only for this-and-future changes. No history is rewritten. */
    public function moveWeekday(TrainingDay $day, string $from, string $to): void
    {
        $source = Carbon::parse($from)->dayOfWeek;
        $target = Carbon::parse($to)->dayOfWeek;
        $this->changeWeekdaysFrom($day, $from, fn ($days) => array_values(array_filter($days, fn ($weekday) => $weekday !== $source)));
        $this->changeWeekdaysFrom($day, max($from, $to), function ($days) use ($target) {
            $days[] = $target;
            $days = array_values(array_unique($days));
            sort($days);

            return $days;
        });
    }

    private function changeWeekdaysFrom(TrainingDay $day, string $from, callable $change): void
    {
        $rules = $day->schedule_rules ?? [['from' => null, 'weekdays' => $day->weekdays ?? []]];
        $current = $day->weekdaysOn($from);
        $next = [];
        foreach ($rules as $rule) {
            if ($rule['from'] !== $from) {
                $next[] = ['from' => $rule['from'], 'weekdays' => $rule['from'] !== null && $rule['from'] > $from ? $change($rule['weekdays']) : $rule['weekdays']];
            }
        }
        $next[] = ['from' => $from, 'weekdays' => $change($current)];
        usort($next, fn ($a, $b) => ($a['from'] ?? '') <=> ($b['from'] ?? ''));
        $day->schedule_rules = $next;
        $day->weekdays = end($next)['weekdays'];
    }

    public function rememberCancellation(ScheduleEntry $entry): void
    {
        if (preg_match('/^plan:([1-9][0-9]*):[1-9][0-9]*:\d{4}-\d{2}-\d{2}$/D', $entry->plan_occurrence_key ?? '', $match) &&
            TrainingPlan::whereKey($match[1])->where('user_id', $entry->user_id)->exists()) {
            DB::table('schedule_occurrence_cancellations')->insertOrIgnore(['training_plan_id' => $match[1], 'occurrence_key' => $entry->plan_occurrence_key, 'created_at' => now()]);
        }
    }

    /** Only explicitly enabled schedules roll forward; reads never activate a plan. */
    public function ensureWindow(User $owner, ?User $viewer = null, ?int $planId = null): void
    {
        $plans = $owner->trainingPlans()->where('schedule_mode', 'ongoing')->where('schedule_enabled', true)->whereNotNull('schedule_actor_id')
            ->when($planId, fn ($q) => $q->whereKey($planId))->orderBy('id')->get(['id', 'schedule_actor_id']);
        foreach ($plans as $candidate) {
            $actor = User::find($candidate->schedule_actor_id);
            if (! $actor || ($viewer && $viewer->id !== $actor->id)) {
                continue;
            }
            $coach = $actor->id !== $owner->id ? $actor : null;
            try {
                $work = function () use ($owner, $candidate, $coach) {
                    User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
                    $plan = TrainingPlan::where('user_id', $owner->id)->lockForUpdate()->findOrFail($candidate->id);
                    if (! $plan->schedule_enabled || $plan->schedule_mode !== 'ongoing' || $plan->schedule_actor_id !== $candidate->schedule_actor_id) {
                        return;
                    }
                    $plan->setRelation('trainingDays', $plan->trainingDays()->lockForUpdate()->get());
                    $preview = $this->preview($plan, lock: true, coach: $coach, rolling: true);
                    // Rolling refresh is additive only. Removal always requires a reviewed diff.
                    foreach ($preview['add'] as $session) {
                        if ($session['replaces_id']) {
                            continue;
                        }
                        $owner->scheduleEntries()->firstOrCreate(['plan_occurrence_key' => $session['key']], [
                            ...collect($session)->except('key', 'existing', 'existing_date', 'replaces_id')->all(),
                            'status' => 'planned', 'schedule_managed' => true, 'scheduled_by_user_id' => $coach?->id ?? $owner->id,
                        ]);
                    }
                };
                $coach ? app(CoachAccess::class)->transaction($coach, $owner, $work) : DB::transaction($work);
            } catch (HttpExceptionInterface|ModelNotFoundException $error) {
                // A paused/ended service, deleted coach or disconnected relationship stops generation.
                if (! $error instanceof ModelNotFoundException && ! in_array($error->getStatusCode(), [403, 404])) {
                    throw $error;
                }
            }
        }
    }
}
