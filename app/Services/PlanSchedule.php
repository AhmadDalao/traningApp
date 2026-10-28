<?php

namespace App\Services;

use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use Carbon\CarbonPeriod;
use Illuminate\Validation\ValidationException;

class PlanSchedule
{
    public function preview(TrainingPlan $plan, bool $lock = false): array
    {
        $plan->loadMissing('trainingDays');
        $entries = ScheduleEntry::where('user_id', $plan->user_id)
            ->where(fn ($q) => $q->whereIn('training_day_id', $plan->trainingDays->modelKeys())
                ->orWhere('plan_occurrence_key', 'like', 'plan:'.$plan->id.':%'))
            ->select('id', 'training_day_id', 'title', 'entry_type', 'scheduled_date', 'scheduled_time', 'status', 'started_at', 'plan_occurrence_key', 'schedule_managed', 'updated_at')
            ->selectRaw('workout_snapshot IS NOT NULL AS has_snapshot')->withExists('sessionExercises')
            ->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $eligible = [];
        $protected = [];
        foreach ($entries as $entry) {
            $reason = $this->protection($entry, $plan);
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
        $add = [];
        $keep = [];
        foreach ($this->desired($plan) as $session) {
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
        $remove = array_values(array_diff_key($eligible, $keep));
        $result = [
            'sessions' => $sessions, 'add' => $add, 'keep' => array_values($keep), 'remove' => $remove, 'protected' => $protected,
            'eligible' => array_values($eligible),
            'new_count' => count(array_filter($add, fn ($row) => ! $row['replaces_id'])),
            'existing_count' => count(array_filter($sessions, fn ($row) => $row['existing'])),
            'replacement_count' => count(array_filter($add, fn ($row) => $row['replaces_id'])),
            'message' => ! $plan->start_date || ! $plan->end_date ? 'Add a start and end date to this plan to generate sessions.' : (count($sessions) ? null : 'No future dates match this plan. Check the period, weekdays and usual times.'),
        ];
        $result['signature'] = hash_hmac('sha256', json_encode([
            $plan->id, $plan->user_id, $plan->only('start_date', 'end_date'),
            $plan->trainingDays->map->getAttributes()->all(), $entries->map->getAttributes()->all(), $result,
        ], JSON_THROW_ON_ERROR), config('app.key'));

        return $result;
    }

    private function desired(TrainingPlan $plan): array
    {
        if (! $plan->start_date || ! $plan->end_date) {
            return [];
        }
        if ($plan->start_date->diffInDays($plan->end_date) > 365) {
            throw ValidationException::withMessages(['schedule' => 'Preview a plan period of up to one year. Shorten the plan dates to continue.']);
        }
        $start = $plan->start_date->copy()->max(today());
        $sessions = [];
        if ($start->lte($plan->end_date)) {
            foreach (CarbonPeriod::create($start, $plan->end_date) as $date) {
                foreach ($plan->trainingDays as $day) {
                    if (! in_array($date->dayOfWeek, $day->weekdays ?? [], false) || ! $this->future($date->toDateString(), $day->default_time)) {
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

    public function apply(TrainingPlan $plan, string $signature, string $action, bool $removeObsolete): array
    {
        // Called inside a transaction holding the user, plan and training-day locks.
        $preview = $this->preview($plan, lock: true);
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
                    'status' => 'planned', 'schedule_managed' => true,
                ]);
                $added += $entry->wasRecentlyCreated ? 1 : 0;
            }
        }

        return ['added' => $added, 'removed' => count($ids)];
    }
}
