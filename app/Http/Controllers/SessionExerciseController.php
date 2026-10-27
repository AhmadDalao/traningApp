<?php

namespace App\Http\Controllers;

use App\Models\SessionExercise;
use App\Services\ActiveWorkout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SessionExerciseController extends Controller
{
    public function status(Request $request, SessionExercise $sessionExercise, ActiveWorkout $workout)
    {
        Gate::authorize('update', $sessionExercise->scheduleEntry);
        $data = $request->validate(['status' => 'required|in:active,skipped', 'confirmed' => 'sometimes|boolean']);
        $workout->change($sessionExercise->scheduleEntry, function ($entry) use ($sessionExercise, $data) {
            $exercise = $entry->sessionExercises()->findOrFail($sessionExercise->id);
            if ($data['status'] === 'skipped' && $exercise->sets()->whereNotNull('completed_at')->exists() && ! ($data['confirmed'] ?? false)) {
                throw ValidationException::withMessages(['confirmed' => 'This exercise has completed sets. Confirm skipping it; its sets will be kept.']);
            }
            $exercise->update(['status' => $data['status']]);
            $exercise->refreshStatus();
        });

        return back();
    }

    public function storeSet(SessionExercise $sessionExercise, ActiveWorkout $workout)
    {
        Gate::authorize('update', $sessionExercise->scheduleEntry);
        $workout->change($sessionExercise->scheduleEntry, function ($entry) use ($sessionExercise, $workout) {
            $exercise = $entry->sessionExercises()->findOrFail($sessionExercise->id);
            if ($exercise->status === 'skipped' || $exercise->sets()->count() >= 99) {
                throw ValidationException::withMessages(['workout' => 'Undo Skip before adding sets. Each exercise can have up to 99 sets.']);
            }
            $workout->addSet($exercise);
            $exercise->refreshStatus();
        });

        return back();
    }
}
