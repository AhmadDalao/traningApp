<?php

namespace App\Http\Controllers;

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
        return Inertia::render('Recommendations/Index', ['recommendations' => $recommendations->forProfile($request->user()->profile()->first())]);
    }

    public function store(Request $request, PlanRecommendations $recommendations, ExerciseLibrary $library)
    {
        $data = $request->validate(['preset' => 'required|string|max:50']);
        $preset = collect($recommendations->forProfile($request->user()->profile()->first()))->firstWhere('key', $data['preset']);
        if (! $preset) {
            throw ValidationException::withMessages(['preset' => 'Your preferences changed. Refresh the recommendations and choose a plan.']);
        }
        $paths = [];
        try {
            $plan = DB::transaction(function () use ($request, $preset, $library, &$paths) {
                $plan = $request->user()->trainingPlans()->create(['name' => $preset['name'], 'description' => $preset['description'].' Customize the exercises and choose plan dates and weekdays before scheduling.']);
                foreach ($preset['days'] as $position => $day) {
                    $trainingDay = $plan->trainingDays()->create(['title' => $day['title'], 'type' => 'training', 'focus_area' => $day['focus_area'], 'position' => $position, 'notes' => 'Suggested routine. Adjust exercises, sets, and reps to suit your training.']);
                    foreach ($day['exercises'] as $order => $exercise) {
                        $trainingDay->exercises()->create([
                            'name' => $exercise['name'], 'icon' => $exercise['icon'], 'sets' => $exercise['sets'], 'reps' => $exercise['reps'],
                            'position' => $order, 'library_key' => $exercise['key'],
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
