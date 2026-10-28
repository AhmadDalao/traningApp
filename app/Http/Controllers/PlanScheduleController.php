<?php

namespace App\Http\Controllers;

use App\Models\TrainingPlan;
use App\Models\User;
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
        $preview = $schedule->preview($trainingPlan);

        return Inertia::render('TrainingPlans/Schedule', ['plan' => $trainingPlan, 'preview' => $preview]);
    }

    public function store(Request $request, TrainingPlan $trainingPlan, PlanSchedule $schedule)
    {
        Gate::authorize('update', $trainingPlan);
        $data = $request->validate([
            'signature' => 'required|string|size:64', 'action' => 'required|in:add,remove,regenerate',
            'confirmed' => 'required|accepted', 'remove_obsolete' => 'sometimes|boolean',
        ]);
        try {
            $result = DB::transaction(function () use ($trainingPlan, $schedule, $data, $request) {
                User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $plan = TrainingPlan::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($trainingPlan->id);
                $plan->setRelation('trainingDays', $plan->trainingDays()->lockForUpdate()->get());

                return $schedule->apply($plan, $data['signature'], $data['action'], $data['remove_obsolete'] ?? false);
            });
        } catch (ValidationException $error) {
            return to_route('training-plans.schedule-preview', $trainingPlan)->withErrors($error->errors());
        }

        return to_route('training-plans.schedule-preview', $trainingPlan)->with('success', "{$result['added']} sessions added. {$result['removed']} future generated sessions removed. All protected sessions were kept.");
    }
}
