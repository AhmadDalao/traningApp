<?php

namespace App\Http\Controllers;

use App\Models\TrainingExercise;
use App\Services\ExerciseProgress;
use App\Services\ExerciseProgression;
use App\Services\TrainingContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ExerciseProgressController extends Controller
{
    public function __invoke(Request $request, ExerciseProgress $progress, ExerciseProgression $progression)
    {
        $context = app(TrainingContext::class);
        $data = $request->validate([
            'exercise' => 'nullable|string|max:255', 'search' => 'nullable|string|max:150',
            'mode' => ['nullable', Rule::in(['reps', 'duration'])],
            'range' => ['nullable', Rule::in(['30', '90', '6m', '1y', 'all'])],
            'page' => 'nullable|integer|min:1',
            'template' => 'nullable|integer|min:1',
        ]);
        if (! empty($data['exercise'])) {
            return Inertia::render('ExerciseProgress/Show', [...$progress->detail(
                $context->owner(), $data['exercise'], $data['mode'] ?? 'reps', $data['range'] ?? '90',
            ), 'progression' => $progression->detail($context->owner(), $data['exercise'], $data['mode'] ?? 'reps', isset($data['template']) ? (int) $data['template'] : null, $context->coaching() ? $request->user() : null)]);
        }

        return Inertia::render('ExerciseProgress/Index', [
            ...$progress->overview($context->owner(), $data['search'] ?? ''), 'search' => $data['search'] ?? '',
        ]);
    }

    public function coachApply(Request $request, TrainingExercise $trainingExercise, ExerciseProgression $progression)
    {
        $context = app(TrainingContext::class);
        $data = $request->validate(['token' => 'required|string|size:64', 'value' => 'present']);
        try {
            $progression->applyForCoach($request->user(), $context->owner(), $trainingExercise, $data['token'], $data['value']);
        } catch (ValidationException $error) {
            return $context->redirect('/progress/exercises')->withErrors($error->errors());
        }

        return back()->with('success', 'Assigned plan target updated. Saved workouts are unchanged.');
    }

    public function apply(Request $request, TrainingExercise $trainingExercise, ExerciseProgression $progression, ExerciseProgress $progress)
    {
        $data = $request->validate([
            'token' => 'required|string|size:64', 'value' => 'present',
            'exercise' => 'sometimes|required|string|max:255', 'mode' => 'sometimes|required|in:reps,duration',
        ]);
        try {
            $progression->apply($request->user(), $trainingExercise, $data['token'], $data['value']);
        } catch (ValidationException $error) {
            if (! isset($error->errors()['progression'])) {
                throw $error;
            }
            // If identity/mode changed, clear the obsolete selection. If all results
            // were reopened/deleted, return to the list instead of a now-empty detail's 404.
            $key = $data['exercise'] ?? $trainingExercise->library_key;
            $mode = $data['mode'] ?? $trainingExercise->tracking_mode ?? 'reps';
            $query = [];
            if ($key && $progress->query($request->user(), $key)->where('exercise.tracking_mode', $mode)->exists()) {
                $query = ['exercise' => $key, 'mode' => $mode];
                $current = $trainingExercise->fresh();
                if ($current && $current->library_key === $key && ($current->tracking_mode ?? 'reps') === $mode && $current->trainingDay->type === 'training') {
                    $query['template'] = $current->id;
                }
            }

            return to_route('progress.exercises', $query)->withErrors($error->errors());
        }

        return back()->with('success', 'Plan target updated. Future workouts will use this target; saved workouts are unchanged.');
    }
}
