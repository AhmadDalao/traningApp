<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSessionSetRequest;
use App\Models\SessionSet;
use App\Services\ActiveWorkout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SessionSetController extends Controller
{
    public function update(UpdateSessionSetRequest $request, SessionSet $sessionSet, ActiveWorkout $workout)
    {
        $workout->change($sessionSet->sessionExercise->scheduleEntry, function ($entry) use ($request, $sessionSet) {
            $set = SessionSet::findOrFail($sessionSet->id);
            $exercise = $entry->sessionExercises()->findOrFail($set->session_exercise_id);
            if ($exercise->status === 'skipped') {
                throw ValidationException::withMessages(['workout' => 'Undo Skip before logging this exercise.']);
            }
            $field = $exercise->tracking_mode === 'duration' ? 'actual_duration_seconds' : 'actual_reps';
            $set->fill($request->safe()->only([$field, 'actual_weight_kg']));
            $completed = $request->has('completed') ? $request->boolean('completed') : $set->completed_at !== null;
            if ($completed && $set->$field === null) {
                throw ValidationException::withMessages([$field => $field === 'actual_reps' ? 'Enter actual reps before completing this set.' : 'Enter actual duration before completing this set.']);
            }
            $set->completed_at = $completed ? ($set->completed_at ?? now()) : null;
            $set->save();
            $exercise->refreshStatus();
        });

        return back();
    }

    public function destroy(Request $request, SessionSet $sessionSet, ActiveWorkout $workout)
    {
        Gate::authorize('update', $sessionSet->sessionExercise->scheduleEntry);
        $workout->change($sessionSet->sessionExercise->scheduleEntry, function ($entry) use ($request, $sessionSet) {
            $set = SessionSet::findOrFail($sessionSet->id);
            $exercise = $entry->sessionExercises()->findOrFail($set->session_exercise_id);
            if ($set->completed_at && ! $request->boolean('confirmed')) {
                throw ValidationException::withMessages(['confirmed' => 'Confirm removal of this completed set.']);
            }
            $set->delete();
            foreach ($exercise->sets()->get() as $index => $remaining) {
                $remaining->update(['set_number' => $index + 1]);
            }
            $exercise->refreshStatus();
        });

        return back();
    }
}
