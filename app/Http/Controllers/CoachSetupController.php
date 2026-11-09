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
            ->withThumbnail()->with('trainingDays:id,training_plan_id,training_block_id,type,weekdays')->withCount('trainingDays')->orderBy('name')->get(['id', 'name', 'description', 'structure_type', 'duration_weeks'])->map(fn ($plan) => [...$plan->attributesToArray(), 'weekly_frequency' => $plan->weeklyFrequency()]);
        $draft = $setup->publicDraft($relationship);
        $assigning = $request->boolean('assign') || $request->filled('template');
        if ($request->filled('template')) {
            abort_unless($templates->contains('id', $request->integer('template')), 404);
        }
        $sameDraft = ! $draft['completed'] && $draft['saved_at']
            && $draft['choices']['selection_type'] === 'template'
            && (int) $draft['choices']['training_plan_id'] === $request->integer('template');
        if ($assigning && ! $sameDraft) {
            // Entering a new assignment changes only the page's choices. Saving remains
            // revision-checked and activation remains the sole domain commit.
            $base = $draft['saved_at'] && ! $draft['completed'] ? $draft['choices'] : $setup->defaults($relationship);
            $draft['choices'] = [...$base, 'selection_type' => 'template', 'training_plan_id' => null, 'day_options' => [], 'time_overrides' => []];
        }
        if ($request->filled('template')) {
            $draft['choices']['selection_type'] = 'template';
            $draft['choices']['training_plan_id'] = $request->integer('template');
        }

        return Inertia::render('Coach/SetupTrainee', [
            'state' => $summary->one($this->context->actor(), $this->context->owner()), 'draft' => $draft, 'templates' => $templates,
            'assigning' => $assigning, 'startAtSchedule' => $request->filled('template'),
            'existingAssignments' => $relationship->assignments()->where('status', 'active')->whereHas('plan')->orderBy('id')->pluck('assigned_training_plan_id', 'source_training_plan_id'),
            'assignedPlans' => $this->context->plans(true)->withThumbnail()->with('trainingDays:id,training_plan_id,training_block_id,type,weekdays')->withCount('trainingDays')->latest('id')->get(['id', 'name', 'description', 'structure_type', 'duration_weeks', 'created_at'])->map(fn ($plan) => [...$plan->attributesToArray(), 'weekly_frequency' => $plan->weeklyFrequency()]),
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
