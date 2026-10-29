<?php

namespace App\Http\Controllers;

use App\Models\ProgramRun;
use App\Models\UserProfile;
use App\Services\ProgramLifecycle;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $profile = $request->user()->profile()->first();
        if ($request->session()->pull('show_onboarding') && (! $profile || $profile->needsOnboarding())) {
            return Inertia::render('Onboarding/Index', ['profile' => $profile, 'options' => UserProfile::options()]);
        }
        $latestWeight = $request->user()->bodyWeightEntries()->orderByDesc('recorded_date')->orderByDesc('id')->first();
        $checkIn = $profile && in_array($profile->body_weight_goal, ['maintain', 'lose', 'gain'], true)
            && (! $latestWeight || $latestWeight->recorded_date->lte(today()->subDays(7)))
            && (! $profile->weight_check_in_dismissed_until || $profile->weight_check_in_dismissed_until->lte(today()));
        $entries = $request->user()->scheduleEntries();
        $week = (clone $entries)->where('entry_type', 'training')->whereBetween('scheduled_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()]);
        $today = (clone $entries)->whereDate('scheduled_date', today())->with(['trainingDay' => fn ($q) => $q->withCount('exercises')])->orderBy('scheduled_time')->orderBy('id')->get();
        $today->each(fn ($entry) => $entry->setAttribute('exercises_count', $entry->workoutExerciseCount()));
        $upcoming = (clone $entries)->upcoming()->whereBetween('scheduled_date', [today()->addDay()->toDateString(), today()->addDays(7)->toDateString()])->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('id');

        return Inertia::render('Dashboard', [
            'programRuns' => app(ProgramLifecycle::class)->cards(ProgramRun::where('user_id', $request->user()->id)->where('status', 'active')),
            'checkIn' => $checkIn ? ['last_weight' => $latestWeight?->weight_kg ?? $profile->current_weight_kg, 'last_date' => $latestWeight?->recorded_date?->toDateString()] : null,
            'profileIncomplete' => ! $profile?->onboarding_completed_at,
            'stats' => ['completed' => (clone $week)->where('status', 'completed')->count(), 'planned' => (clone $week)->where('status', 'planned')->count(), 'today' => $today->count(), 'upcoming' => (clone $upcoming)->count()],
            'activeWorkout' => $request->user()->scheduleEntries()->activeWorkout()->first(['id', 'title', 'started_at']),
            'todayEntries' => $today, 'upcomingEntries' => $upcoming->get(),
            'plans' => $request->user()->personalPlans()->with('trainingDays')->orderBy('name')->get(),
        ]);
    }
}
