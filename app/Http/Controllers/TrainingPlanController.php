<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTrainingPlanRequest;
use App\Http\Requests\UpdateTrainingPlanRequest;
use App\Models\TrainingPlan;
use App\Services\ProgramDefinition;
use App\Services\ProgramLifecycle;
use App\Services\TrainingContext;
use App\Services\TrainingPlanDuplicator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class TrainingPlanController extends Controller
{
    public function __construct(private TrainingContext $context) {}

    public function index(Request $request)
    {
        return Inertia::render('TrainingPlans/Index', ['plans' => $this->context->plans()->with('assignment.relationship.coach.coachProfile')->withCount([
            'trainingDays', 'trainingDays as workout_days_count' => fn ($q) => $q->where('type', 'training'),
            'trainingDays as rest_days_count' => fn ($q) => $q->where('type', 'rest'),
        ])->latest()->paginate(12)->through(fn ($plan) => $this->describe($plan))]);
    }

    private function describe(TrainingPlan $plan): array
    {
        return [...$plan->attributesToArray(), 'assignment_id' => $plan->assignment?->id, 'assignment_status' => $plan->assignment?->status, 'coach_name' => $plan->workspace_type === 'coach_assigned' ? $plan->assignment?->relationship?->coach?->coachProfile?->display_name : null];
    }

    public function create()
    {
        return Inertia::render('TrainingPlans/Form');
    }

    public function store(StoreTrainingPlanRequest $request)
    {
        $plan = $this->context->write(function ($relationship = null, $subscription = null) use ($request) {
            $plan = $this->context->owner()->trainingPlans()->make($request->validated());
            $plan->workspace_type = $this->context->templates() ? 'coach_template' : ($this->context->coaching() ? 'coach_assigned' : 'personal');
            $plan->save();
            if ($plan->isProgram()) {
                $plan->blocks()->create(['name' => 'Foundation', 'start_week' => 1, 'end_week' => $plan->duration_weeks, 'position' => 0, 'type' => 'normal']);
            }
            if ($relationship) {
                $relationship->assignments()->create(['coach_subscription_id' => $subscription->id, 'assigned_training_plan_id' => $plan->id, 'assigned_at' => now(), 'status' => 'active']);
            }

            return $plan;
        });

        return $this->context->redirect('/training-plans/'.$plan->id)->with('success', 'Training plan created. Add your first day.');
    }

    public function show(TrainingPlan $trainingPlan)
    {
        $this->context->plan($trainingPlan);

        if ($trainingPlan->isProgram()) {
            return Inertia::render('Programs/Show', ['plan' => $this->describe($trainingPlan),
                'blocks' => $trainingPlan->blocks()->with('trainingDays.exercises')->get()->map(fn ($block) => [...$block->toArray(),
                    'editable' => ! $this->context->coaching() || app(ProgramDefinition::class)->coachCanEditBlock($block)]),
                'runs' => $trainingPlan->programRuns()->get()->map(fn ($run) => app(ProgramLifecycle::class)->sync($run->id))]);
        }

        return Inertia::render('TrainingPlans/Show', ['plan' => [...$this->describe($trainingPlan), 'training_days' => $trainingPlan->trainingDays()->withCount('exercises')->get()],
            'copyPlans' => $this->context->plans(true)->where('structure_type', 'simple')->whereKeyNot($trainingPlan->id)->orderBy('name')->get(['id', 'name'])]);
    }

    public function edit(TrainingPlan $trainingPlan)
    {
        $this->context->plan($trainingPlan, 'update');

        return Inertia::render('TrainingPlans/Form', ['plan' => $trainingPlan]);
    }

    public function update(UpdateTrainingPlanRequest $request, TrainingPlan $trainingPlan)
    {
        $changed = $this->context->write(function () use ($request, $trainingPlan) {
            $plan = TrainingPlan::lockForUpdate()->findOrFail($trainingPlan->id);
            $this->context->plan($plan, 'update');
            $plan->update($request->validated());

            $changed = $plan->wasChanged(['start_date', 'end_date', 'schedule_mode']);
            if ($changed) {
                $plan->forceFill(['schedule_enabled' => false])->save();
            }

            return $changed;
        });

        return $this->context->redirect('/training-plans/'.$trainingPlan->id)->with('success', 'Training plan updated.')
            ->with('schedule_review', $changed && ! $this->context->templates() ? $trainingPlan->only('id', 'name') : null);
    }

    public function duplicate(Request $request, TrainingPlan $trainingPlan, TrainingPlanDuplicator $duplicator)
    {
        $this->context->plan($trainingPlan, 'update');
        abort_if($this->context->coaching(), 403, 'Use Assign Plan to create another trainee-owned copy.');
        $request->validate(['confirmed' => 'required|accepted']);
        $copy = $duplicator->plan($request->user(), $trainingPlan);

        return $this->context->redirect('/training-plans/'.$copy->id)->with('success', 'Plan duplicated. Your copy has no Calendar sessions or workout history.');
    }

    public function destroy(TrainingPlan $trainingPlan)
    {
        $this->context->plan($trainingPlan, 'delete');
        $paths = $this->context->write(function () use ($trainingPlan) {
            $plan = TrainingPlan::lockForUpdate()->findOrFail($trainingPlan->id);
            $this->context->plan($plan, 'delete');
            $paths = $plan->trainingDays()->with('exercises')->get()->flatMap(fn ($day) => $day->exercises->flatMap(fn ($exercise) => [$exercise->thumbnail_path, $exercise->custom_icon_path]))->filter()->all();
            $plan->assignment()->update(['status' => 'ended', 'ended_at' => now()]);
            $plan->delete();

            return $paths;
        });
        Storage::disk('public')->delete($paths);

        return $this->context->redirect('/training-plans')->with('success', 'Plan deleted. Your calendar history is preserved.');
    }
}
