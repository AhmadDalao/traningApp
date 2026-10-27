<?php

namespace App\Services;

use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use Carbon\CarbonPeriod;
use Illuminate\Validation\ValidationException;

class PlanSchedule
{
    public function preview(TrainingPlan $plan): array
    {
        $plan->loadMissing('trainingDays');
        if (! $plan->start_date || ! $plan->end_date) {
            return ['sessions' => [], 'signature' => '', 'new_count' => 0, 'existing_count' => 0, 'message' => 'Add a start and end date to this plan first.'];
        }
        if ($plan->start_date->diffInDays($plan->end_date) > 365) {
            throw ValidationException::withMessages(['schedule' => 'Preview a plan period of up to one year. Shorten the plan dates to continue.']);
        }
        $sessions = [];
        $start = $plan->start_date->max(today());
        $entries = ScheduleEntry::where('user_id', $plan->user_id)
            ->where(fn ($query) => $query
                ->where(fn ($dates) => $dates->whereIn('training_day_id', $plan->trainingDays->modelKeys())
                    ->whereBetween('scheduled_date', [$start->toDateString(), $plan->end_date->toDateString()]))
                ->orWhere('plan_occurrence_key', 'like', 'plan:'.$plan->id.':%'))
            ->get();
        $existing = $entries->keyBy(fn ($entry) => $entry->training_day_id.':'.$entry->scheduled_date->toDateString());
        $occurrences = $entries->whereNotNull('plan_occurrence_key')->keyBy('plan_occurrence_key');
        if ($start->lte($plan->end_date)) {
            foreach (CarbonPeriod::create($start, $plan->end_date) as $date) {
                foreach ($plan->trainingDays as $day) {
                    if (! in_array($date->dayOfWeek, $day->weekdays ?? [], false)) {
                        continue;
                    }
                    $key = $day->id.':'.$date->toDateString();
                    $occurrenceKey = 'plan:'.$plan->id.':'.$key;
                    $booked = $occurrences->get($occurrenceKey) ?? $existing->get($key);
                    $sessions[] = [
                        'key' => $occurrenceKey, 'training_day_id' => $day->id,
                        'title' => $day->title, 'entry_type' => $day->type, 'focus_area' => $day->focus_area,
                        'icon' => $day->icon, 'scheduled_date' => $date->toDateString(), 'scheduled_time' => $day->default_time,
                        'notes' => $day->notes, 'existing' => $booked !== null,
                        'existing_date' => $booked && ! $booked->scheduled_date->isSameDay($date) ? $booked->scheduled_date->toDateString() : null,
                    ];
                    if (count($sessions) > 1000) {
                        throw ValidationException::withMessages(['schedule' => 'This preview exceeds 1,000 sessions. Shorten the plan dates to continue.']);
                    }
                }
            }
        }
        $newCount = count(array_filter($sessions, fn ($session) => ! $session['existing']));

        return [
            'sessions' => $sessions,
            'signature' => hash('sha256', json_encode([$plan->id, $plan->start_date->toDateString(), $plan->end_date->toDateString(), array_map(fn ($row) => array_diff_key($row, ['existing' => true, 'existing_date' => true]), $sessions)], JSON_THROW_ON_ERROR)),
            'new_count' => $newCount, 'existing_count' => count($sessions) - $newCount,
            'message' => count($sessions) ? null : 'No upcoming dates match this plan. Check the plan dates and each workout’s weekdays.',
        ];
    }
}
