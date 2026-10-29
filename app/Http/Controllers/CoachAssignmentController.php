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

        return Inertia::render('Coach/Assign', ['templates' => $context->actor()->trainingPlans()->where('workspace_type', 'coach_template')->orderBy('name')->get(['id', 'name', 'description', 'structure_type']), 'subscription' => $subscription?->summary()]);
    }

    public function store(Request $request, TrainingContext $context, TrainingPlanDuplicator $duplicator)
    {
        $request->validate(['training_plan_id' => 'required|integer']);
        $source = TrainingPlan::where('user_id', $request->user()->id)->where('workspace_type', 'coach_template')->findOrFail($request->integer('training_plan_id'));
        if ($source->isProgram()) {
            $request->merge(['start_date' => null, 'end_date' => null, 'schedule_mode' => 'fixed']);
        }
        $data = $request->validate(['training_plan_id' => 'required|integer', 'start_date' => ($source->isProgram() ? 'nullable' : 'required').'|date_format:Y-m-d', 'schedule_mode' => 'sometimes|in:fixed,ongoing', 'end_date' => ($source->isProgram() || $request->input('schedule_mode') === 'ongoing' ? 'nullable' : 'required').'|date_format:Y-m-d|after_or_equal:start_date', 'confirmed' => 'required|accepted']);
        if (($data['schedule_mode'] ?? 'fixed') === 'ongoing') {
            $data['end_date'] = null;
        }
        $source = TrainingPlan::where('user_id', $request->user()->id)->where('workspace_type', 'coach_template')->findOrFail($data['training_plan_id']);
        $copy = $duplicator->assign($request->user(), $context->owner(), $source, collect($data)->only('start_date', 'end_date', 'schedule_mode')->all());

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
