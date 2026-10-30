<?php

namespace App\Http\Controllers;

use App\Models\CoachSubscriptionPlan;
use App\Models\ProgramRun;
use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use App\Services\CoachAccess;
use App\Services\CoachingSubscriptions;
use App\Services\CoachWorkspaceSummary;
use App\Services\Notifications\CoachNotifications;
use App\Services\PlanSchedule;
use App\Services\ProgramSchedule;
use App\Services\TrainingContext;
use App\Services\TrainingPlanDuplicator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Coordinates saved setup steps; domain services own authorization and mutations. */
class CoachSetupController extends Controller
{
    public function __construct(private TrainingContext $context) {}

    public function show(Request $request, CoachWorkspaceSummary $summary, CoachAccess $access)
    {
        $state = $summary->one($this->context->actor(), $this->context->owner());
        $plan = $state['plan'] ? $this->context->plans(true)->find($state['plan']['id']) : null;
        $templates = $this->context->actor()->trainingPlans()->where('workspace_type', 'coach_template')->orderBy('name')->get(['id', 'name', 'description', 'structure_type', 'duration_weeks']);
        $selected = $templates->firstWhere('id', $request->integer('template'));

        return Inertia::render('Coach/SetupTrainee', [
            'state' => $state, 'plan' => $plan, 'templates' => $templates,
            'packages' => CoachSubscriptionPlan::where('coach_user_id', $this->context->actor()->id)->orderBy('name')->get(),
            'selectedTemplate' => $selected?->id,
            'relationshipId' => $request->attributes->get('coach_relationship')->id,
        ]);
    }

    public function coaching(Request $request, CoachingSubscriptions $subscriptions)
    {
        $data = $request->validate($subscriptions::rules());
        $subscriptions->start($this->context->actor(), $this->context->owner(), $data, true, $request->attributes->get('coach_relationship')->id);

        return $this->context->redirect('/setup')->with('success', 'Coaching saved. Continue with training, or finish later.');
    }

    public function training(Request $request, TrainingPlanDuplicator $duplicator)
    {
        $request->validate(['training_plan_id' => 'required|integer', 'confirmed' => 'required|accepted']);
        $source = $this->context->actor()->trainingPlans()->where('workspace_type', 'coach_template')->findOrFail($request->integer('training_plan_id'));
        $data = $request->validate(['start_date' => ($source->isProgram() ? 'nullable' : 'required').'|date_format:Y-m-d', 'schedule_mode' => 'required|in:fixed,ongoing', 'end_date' => ($source->isProgram() || $request->input('schedule_mode') === 'ongoing' ? 'nullable' : 'required').'|date_format:Y-m-d|after_or_equal:start_date']);
        if ($data['schedule_mode'] === 'ongoing') {
            $data['end_date'] = null;
        }
        $duplicator->assign($this->context->actor(), $this->context->owner(), $source, $data, true);

        return $this->context->redirect('/setup')->with('success', 'Training saved as an independent trainee-owned copy. Preview its schedule next.');
    }

    private function plan(Request $request): TrainingPlan
    {
        $request->validate(['training_plan_id' => 'required|integer']);
        $plan = $this->context->plans(true)->findOrFail($request->integer('training_plan_id'));
        $this->context->plan($plan, 'update');

        return $plan;
    }

    public function preview(Request $request, ProgramSchedule $programs, PlanSchedule $plans)
    {
        $plan = $this->plan($request);
        if ($plan->isProgram()) {
            $data = $request->validate(['start_date' => 'required|date_format:Y-m-d|after_or_equal:today', 'nonce' => 'required|uuid']);
            $preview = $programs->preview($plan, $data['start_date'], $data['nonce'], $this->context->actor());
        } else {
            $preview = $plans->preview($plan, coach: $this->context->actor());
        }

        return response()->json(['preview' => $preview]);
    }

    public function activate(Request $request, ProgramSchedule $programs, PlanSchedule $plans)
    {
        $plan = $this->plan($request);
        $data = $request->validate(['signature' => 'required|string|size:64', 'confirmed' => 'required|accepted', 'start_date' => ($plan->isProgram() ? 'required' : 'nullable').'|date_format:Y-m-d|after_or_equal:today', 'nonce' => ($plan->isProgram() ? 'required' : 'nullable').'|uuid']);
        $paths = [];
        try {
            $this->context->write(function () use ($plan, $data, $programs, $plans, &$paths) {
                $plan = TrainingPlan::lockForUpdate()->findOrFail($plan->id);
                $this->context->plan($plan, 'update');
                // Setup only starts the first schedule. Repeat runs and changes use their existing flows.
                if ($plan->isProgram()) {
                    if (! ProgramRun::where('training_plan_id', $plan->id)->lockForUpdate()->exists()) {
                        $programs->start($plan, $data, $this->context->actor(), $paths);
                    }
                } else {
                    $existing = ScheduleEntry::where('user_id', $plan->user_id)->whereHas('trainingDay', fn ($q) => $q->where('training_plan_id', $plan->id))->lockForUpdate()->exists();
                    if (! $existing) {
                        $plan->setRelation('trainingDays', $plan->trainingDays()->lockForUpdate()->get());
                        $preview = $plans->preview($plan, coach: $this->context->actor());
                        if (! $preview['new_count']) {
                            throw ValidationException::withMessages(['schedule' => $preview['message'] ?? 'Add future training dates before activating.']);
                        }
                        $plans->apply($plan, $data['signature'], 'add', false, $this->context->actor());
                    }
                }
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($paths);
            throw $error;
        }

        return response()->json(['url' => $this->context->url('')]);
    }

    public function finish(CoachAccess $access)
    {
        $access->transaction($this->context->actor(), $this->context->owner(), function ($relationship) {
            $assignment = $relationship->assignments()->where('status', 'active')->whereHas('plan')->latest('id')->first();
            if ($assignment && ! ScheduleEntry::whereHas('trainingDay', fn ($q) => $q->where('training_plan_id', $assignment->assigned_training_plan_id))->exists()) {
                app(CoachNotifications::class)->plan($this->context->actor(), $assignment->plan, 'assigned', (string) $assignment->assigned_training_plan_id);
            }
        }, false);

        return $this->context->redirect('')->with('success', 'Setup progress saved. Continue whenever you are ready.');
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
