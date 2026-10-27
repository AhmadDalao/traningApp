<?php

namespace App\Http\Controllers;

use App\Models\TrainingPlan;
use App\Services\PlanSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PlanScheduleController extends Controller
{
    public function preview(TrainingPlan $trainingPlan, PlanSchedule $schedule)
    {
        Gate::authorize('update', $trainingPlan);

        return Inertia::render('TrainingPlans/Schedule', ['plan' => $trainingPlan, 'preview' => $schedule->preview($trainingPlan)]);
    }

    public function store(Request $request, TrainingPlan $trainingPlan, PlanSchedule $schedule)
    {
        Gate::authorize('update', $trainingPlan);
        $data = $request->validate(['signature' => 'required|string|size:64']);
        $count = DB::transaction(function () use ($trainingPlan, $schedule, $data) {
            $plan = TrainingPlan::whereKey($trainingPlan->id)->lockForUpdate()->firstOrFail();
            $plan->setRelation('trainingDays', $plan->trainingDays()->lockForUpdate()->get());
            $preview = $schedule->preview($plan);
            if (! hash_equals($preview['signature'], $data['signature'])) {
                throw ValidationException::withMessages(['schedule' => 'The plan has changed. Refresh this preview before adding sessions.']);
            }
            $count = 0;
            foreach ($preview['sessions'] as $session) {
                if ($session['existing']) {
                    continue;
                }
                $entry = $plan->user->scheduleEntries()->firstOrCreate(['plan_occurrence_key' => $session['key']], [
                    ...collect($session)->except('key', 'existing', 'existing_date')->all(),
                    'status' => 'planned',
                ]);
                $count += $entry->wasRecentlyCreated ? 1 : 0;
            }

            return $count;
        });

        return to_route('training-plans.show', $trainingPlan)->with('success', "$count sessions added to your calendar. Existing sessions were kept.");
    }
}
