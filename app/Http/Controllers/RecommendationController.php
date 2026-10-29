<?php

namespace App\Http\Controllers;

use App\Models\UserProfile;
use App\Services\ExerciseLibrary;
use App\Services\PlanRecommendations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class RecommendationController extends Controller
{
    public function index(Request $request, PlanRecommendations $recommendations)
    {
        $catalog = $recommendations->catalog();
        $profile = $request->user()->profile()->first();

        return Inertia::render('Recommendations/Index', [
            'plans' => array_map(fn ($plan) => $this->summary($plan), $catalog),
            'recommendations' => array_map(fn ($plan) => $this->summary($plan), $recommendations->forProfile($profile, $catalog)),
            'options' => UserProfile::options(),
            'hasPreferences' => (bool) ($profile?->training_goals || $profile?->experience_level || $profile?->equipment_access),
        ]);
    }

    public function show(Request $request, string $preset, PlanRecommendations $recommendations)
    {
        $plan = collect($recommendations->catalog())->firstWhere('key', $preset);
        abort_unless($plan, 404);

        return Inertia::render('Recommendations/Show', [
            'plan' => [...$plan, ...$recommendations->match($plan, $request->user()->profile()->first())],
        ]);
    }

    private function summary(array $plan): array
    {
        // Discovery cards need metadata, not every exercise in all 19 plans.
        unset($plan['days'], $plan['score'], $plan['enabled']);

        return $plan;
    }

    public function store(Request $request, PlanRecommendations $recommendations, ExerciseLibrary $library)
    {
        $data = $request->validate(['preset' => 'required|string|max:50']);
        $preset = collect($recommendations->catalog())->firstWhere('key', $data['preset']);
        if (! $preset) {
            throw ValidationException::withMessages(['preset' => 'This plan is unavailable. Choose a plan from the library.']);
        }
        $paths = [];
        try {
            $plan = DB::transaction(function () use ($request, $preset, $library, &$paths) {
                $plan = $request->user()->trainingPlans()->create(['name' => $preset['name'], 'description' => $preset['description'].' Customize the exercises and choose plan dates and weekdays before scheduling.']);
                foreach ($preset['days'] as $position => $day) {
                    $trainingDay = $plan->trainingDays()->create(['title' => $day['title'], 'type' => 'training', 'focus_area' => $day['focus_area'], 'position' => $position, 'notes' => $day['notes']]);
                    foreach ($day['exercises'] as $order => $exercise) {
                        $trainingDay->exercises()->create([
                            'name' => $exercise['name'], 'icon' => $exercise['icon'], 'sets' => $exercise['sets'], 'reps' => $exercise['reps'],
                            'position' => $order, 'library_key' => $exercise['key'],
                            'tracking_mode' => $exercise['tracking_mode'], 'target_weight' => null,
                            'superset_group' => $exercise['superset_group'], 'notes' => $exercise['notes'],
                            'thumbnail_path' => $library->copyMedia($exercise, 'thumbnail_path', 'exercise-thumbnails', $paths),
                        ]);
                    }
                }

                return $plan;
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($paths);
            throw $error;
        }

        return to_route('training-plans.show', $plan)->with('success', 'Plan added. Customize it, then preview its schedule when you’re ready.');
    }
}
