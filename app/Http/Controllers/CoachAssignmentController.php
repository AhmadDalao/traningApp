<?php

namespace App\Http\Controllers;

use App\Models\CoachPlanAssignment;
use App\Models\CoachTraineeRelationship;
use App\Models\TrainingPlan;
use App\Services\CoachAccess;
use App\Services\TrainingContext;
use App\Services\TrainingPlanDuplicator;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CoachAssignmentController extends Controller
{
    public function choose(Request $request, TrainingPlan $trainingPlan, TrainingContext $context)
    {
        $context->plan($trainingPlan);
        abort_unless($context->templates(), 403);
        $data = $request->validate(['search' => 'nullable|string|max:100']);
        $relationships = CoachTraineeRelationship::where('coach_user_id', $context->actor()->id)->where('status', 'active')->with('trainee:id,name,training_code')->withExists(['assignments as has_training' => fn ($q) => $q->where('status', 'active')->whereHas('plan')])
            ->when(! empty($data['search']), fn ($q) => $q->whereHas('trainee', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%')->orWhere('training_code', 'like', '%'.$data['search'].'%'))))
            ->latest('id')->paginate(25)->withQueryString();

        return Inertia::render('Coach/ChooseTrainee', ['plan' => $trainingPlan->only('id', 'name', 'structure_type', 'duration_weeks'), 'relationships' => $relationships, 'search' => $data['search'] ?? '']);
    }

    public function create(Request $request, TrainingContext $context, CoachAccess $access)
    {
        $subscription = $access->subscription($access->relationship($context->actor(), $context->owner()));

        return Inertia::render('Coach/Assign', ['selectedTemplate' => $request->integer('template'), 'templates' => $context->actor()->trainingPlans()->where('workspace_type', 'coach_template')->orderBy('name')->get(['id', 'name', 'description', 'structure_type']), 'subscription' => $subscription?->summary()]);
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
