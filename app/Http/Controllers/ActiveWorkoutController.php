<?php

namespace App\Http\Controllers;

use App\Models\ScheduleEntry;
use App\Services\ActiveWorkout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ActiveWorkoutController extends Controller
{
    public function start(ScheduleEntry $scheduleEntry, ActiveWorkout $workout)
    {
        Gate::authorize('update', $scheduleEntry);
        $active = $workout->start($scheduleEntry);
        $response = to_route('workout.show', $active);

        return $active->id === $scheduleEntry->id ? $response : $response->with('success', 'You already have a workout in progress. Resume it here.');
    }

    public function show(ScheduleEntry $scheduleEntry)
    {
        Gate::authorize('view', $scheduleEntry);
        if (! $scheduleEntry->isActiveWorkout()) {
            return to_route('schedule-entries.show', $scheduleEntry);
        }

        return Inertia::render('ActiveWorkout/Show', ['entry' => $scheduleEntry->load('sessionExercises.sets')]);
    }

    public function finish(Request $request, ScheduleEntry $scheduleEntry, ActiveWorkout $workout)
    {
        Gate::authorize('update', $scheduleEntry);
        $request->validate(['confirm_incomplete' => 'sometimes|boolean']);
        $workout->finish($scheduleEntry, $request->boolean('confirm_incomplete'));

        return to_route('schedule-entries.show', $scheduleEntry)->with('success', 'Workout completed. Your sets are saved.');
    }

    public function discard(Request $request, ScheduleEntry $scheduleEntry, ActiveWorkout $workout)
    {
        Gate::authorize('update', $scheduleEntry);
        $request->validate(['confirmed' => 'required|accepted']);
        $workout->change($scheduleEntry, function (ScheduleEntry $entry) {
            $entry->sessionExercises()->delete();
            $entry->update(['started_at' => null]);
        });

        return to_route('schedule-entries.show', $scheduleEntry)->with('success', 'Detailed progress discarded. The session is Planned.');
    }
}
