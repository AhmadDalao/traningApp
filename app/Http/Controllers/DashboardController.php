<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $entries = $request->user()->scheduleEntries();
        $week = (clone $entries)->where('entry_type', 'training')->whereBetween('scheduled_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()]);
        $today = (clone $entries)->whereDate('scheduled_date', today())->with(['trainingDay' => fn ($q) => $q->withCount('exercises')])->orderBy('id')->get();
        $upcoming = (clone $entries)->whereBetween('scheduled_date', [today()->addDay()->toDateString(), today()->addDays(7)->toDateString()])->orderBy('scheduled_date')->orderBy('id');

        return Inertia::render('Dashboard', [
            'stats' => ['completed' => (clone $week)->where('status', 'completed')->count(), 'planned' => (clone $week)->where('status', 'planned')->count(), 'today' => $today->count(), 'upcoming' => (clone $upcoming)->count()],
            'todayEntries' => $today, 'upcomingEntries' => $upcoming->limit(8)->get(),
            'plans' => $request->user()->trainingPlans()->with('trainingDays')->orderBy('name')->get(),
        ]);
    }
}
