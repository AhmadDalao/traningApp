<?php

namespace App\Http\Controllers;

use App\Models\CoachPlanAssignment;
use App\Models\TrainingPlan;
use App\Services\CoachAccess;
use App\Services\TrainingContext;
use App\Services\TrainingPlanDuplicator;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CoachAssignmentController extends Controller
{
    public function create(TrainingContext $context, CoachAccess $access)
    {
        $subscription = $access->subscription($access->relationship($context->actor(), $context->owner()));

        return Inertia::render('Coach/Assign', ['templates' => $context->actor()->trainingPlans()->where('workspace_type', 'coach_template')->orderBy('name')->get(['id', 'name', 'description']), 'subscription' => $subscription?->summary()]);
    }

    public function store(Request $request, TrainingContext $context, TrainingPlanDuplicator $duplicator)
    {
        $data = $request->validate(['training_plan_id' => 'required|integer', 'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date', 'confirmed' => 'required|accepted']);
        $source = TrainingPlan::where('user_id', $request->user()->id)->where('workspace_type', 'coach_template')->findOrFail($data['training_plan_id']);
        $copy = $duplicator->assign($request->user(), $context->owner(), $source, collect($data)->only('start_date', 'end_date')->all());

        return $context->redirect('/training-plans/'.$copy->id)->with('success', 'Plan assigned as an independent trainee-owned copy. Review its schedule to add sessions.');
    }

    public function end(Request $request, CoachPlanAssignment $assignment, TrainingContext $context, CoachAccess $access)
    {
        $request->validate(['confirmed' => 'required|accepted']);
        $access->transaction($request->user(), $context->owner(), function ($relationship) use ($assignment) {
            $row = $relationship->assignments()->lockForUpdate()->findOrFail($assignment->id);
            $row->update(['status' => 'ended', 'ended_at' => now()]);
        }, false);

        return $context->redirect('/training-plans')->with('success', 'Assignment ended. The trainee keeps the plan and all sessions.');
    }
}
