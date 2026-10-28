<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTrainingPlanRequest;
use App\Http\Requests\UpdateTrainingPlanRequest;
use App\Models\TrainingPlan;
use App\Services\TrainingPlanDuplicator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class TrainingPlanController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('TrainingPlans/Index', ['plans' => $request->user()->trainingPlans()->withCount([
            'trainingDays', 'trainingDays as workout_days_count' => fn ($q) => $q->where('type', 'training'),
            'trainingDays as rest_days_count' => fn ($q) => $q->where('type', 'rest'),
        ])->latest()->paginate(12)]);
    }

    public function create()
    {
        return Inertia::render('TrainingPlans/Form');
    }

    public function store(StoreTrainingPlanRequest $request)
    {
        $plan = $request->user()->trainingPlans()->create($request->validated());

        return to_route('training-plans.show', $plan)->with('success', 'Training plan created. Add your first day.');
    }

    public function show(TrainingPlan $trainingPlan)
    {
        Gate::authorize('view', $trainingPlan);

        return Inertia::render('TrainingPlans/Show', ['plan' => $trainingPlan->load(['trainingDays' => fn ($q) => $q->withCount('exercises')]),
            'copyPlans' => $trainingPlan->user->trainingPlans()->whereKeyNot($trainingPlan->id)->orderBy('name')->get(['id', 'name'])]);
    }

    public function edit(TrainingPlan $trainingPlan)
    {
        Gate::authorize('update', $trainingPlan);

        return Inertia::render('TrainingPlans/Form', ['plan' => $trainingPlan]);
    }

    public function update(UpdateTrainingPlanRequest $request, TrainingPlan $trainingPlan)
    {
        $trainingPlan->update($request->validated());

        return to_route('training-plans.show', $trainingPlan)->with('success', 'Training plan updated.')
            ->with('schedule_review', $trainingPlan->wasChanged(['start_date', 'end_date']) ? $trainingPlan->only('id', 'name') : null);
    }

    public function duplicate(Request $request, TrainingPlan $trainingPlan, TrainingPlanDuplicator $duplicator)
    {
        Gate::authorize('update', $trainingPlan);
        $request->validate(['confirmed' => 'required|accepted']);
        $copy = $duplicator->plan($request->user(), $trainingPlan);

        return to_route('training-plans.show', $copy)->with('success', 'Plan duplicated. Your copy has no Calendar sessions or workout history.');
    }

    public function destroy(TrainingPlan $trainingPlan)
    {
        Gate::authorize('delete', $trainingPlan);
        $paths = $trainingPlan->trainingDays()->with('exercises')->get()->flatMap(fn ($day) => $day->exercises->flatMap(fn ($exercise) => [$exercise->thumbnail_path, $exercise->custom_icon_path]))->filter()->all();
        $trainingPlan->delete();
        Storage::disk('public')->delete($paths);

        return to_route('training-plans.index')->with('success', 'Plan deleted. Your calendar history is preserved.');
    }
}
