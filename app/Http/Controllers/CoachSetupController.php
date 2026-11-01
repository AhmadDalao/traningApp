<?php

namespace App\Http\Controllers;

use App\Models\CoachSubscriptionPlan;
use App\Services\CoachAccess;
use App\Services\CoachSetup;
use App\Services\CoachWorkspaceSummary;
use App\Services\TrainingContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CoachSetupController extends Controller
{
    public function __construct(private TrainingContext $context) {}

    public function show(Request $request, CoachSetup $setup, CoachWorkspaceSummary $summary)
    {
        $relationship = $request->attributes->get('coach_relationship');
        $templates = $this->context->actor()->trainingPlans()->where('workspace_type', 'coach_template')
            ->withCount('trainingDays')->orderBy('name')->get(['id', 'name', 'description', 'structure_type', 'duration_weeks']);
        $draft = $setup->publicDraft($relationship);
        if (! $relationship->setup_draft && $templates->contains('id', $request->integer('template'))) {
            $draft['choices']['selection_type'] = 'template';
            $draft['choices']['training_plan_id'] = $request->integer('template');
        }

        return Inertia::render('Coach/SetupTrainee', [
            'state' => $summary->one($this->context->actor(), $this->context->owner()), 'draft' => $draft, 'templates' => $templates,
            'assignedPlans' => $this->context->plans(true)->withCount('trainingDays')->latest('id')->get(['id', 'name', 'description', 'structure_type', 'duration_weeks', 'created_at']),
            'packages' => CoachSubscriptionPlan::where('coach_user_id', $this->context->actor()->id)->where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function save(Request $request, CoachSetup $setup)
    {
        return response()->json(['draft' => $setup->save($this->context->actor(), $this->context->owner(), $request->all())]);
    }

    public function preview(Request $request, CoachSetup $setup)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'token' => 'required|uuid']);

        return response()->json(['preview' => $setup->preview($this->context->actor(), $this->context->owner(), $data['revision'], $data['token'])]);
    }

    public function activate(Request $request, CoachSetup $setup)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'token' => 'required|uuid', 'signature' => 'required|string|size:64', 'confirmed' => 'required|accepted']);
        $result = $setup->activate($this->context->actor(), $this->context->owner(), $data);
        $request->session()->flash('success', $result['message']);

        return response()->json(collect($result)->only('url', 'message', 'added'));
    }

    public function legacy()
    {
        abort(409, 'Coach setup has changed. Refresh this page to use Training → Dates & Schedule → Review & Activate.');
    }

    public function manage(CoachAccess $access)
    {
        $relationship = $access->relationship($this->context->actor(), $this->context->owner());

        return Inertia::render('Coach/ManageCoaching', [
            'relationship' => $relationship->only('id', 'status'),
            'packages' => CoachSubscriptionPlan::where('coach_user_id', $this->context->actor()->id)->orderBy('name')->get(),
            'subscriptions' => $relationship->subscriptions()->latest('id')->get()->map(fn ($s) => [...$s->toArray(), 'effective_status' => $s->effective_status, 'relationship' => ['status' => $relationship->status, 'trainee' => $this->context->owner()->only('name')]]),
        ]);
    }
}
