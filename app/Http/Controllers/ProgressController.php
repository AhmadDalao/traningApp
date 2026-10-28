<?php

namespace App\Http\Controllers;

use App\Services\BodyWeightHistory;
use App\Services\TrainingContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProgressController extends Controller
{
    public function __invoke(Request $request, BodyWeightHistory $history)
    {
        $user = app(TrainingContext::class)->owner();
        $month = $user->scheduleEntries()->where('entry_type', 'training')->past()->whereBetween('scheduled_date', [today()->startOfMonth()->toDateString(), today()->toDateString()]);
        $completed = (clone $month)->where('status', 'completed')->count();
        $skipped = (clone $month)->where('status', 'skipped')->count();
        $total = (clone $month)->count();

        return Inertia::render('Progress/Index', [
            'initialTab' => $request->query('tab') === 'training' ? 'training' : 'body',
            'summary' => $history->summary($user),
            'trend' => $user->bodyWeightEntries()->orderBy('recorded_date')->orderBy('id')->get(['id', 'weight_kg', 'recorded_date']),
            'entries' => $user->bodyWeightEntries()->orderByDesc('recorded_date')->orderByDesc('id')->paginate(10),
            'training' => ['completed' => $completed, 'skipped' => $skipped, 'due' => $total, 'completion_rate' => $total ? round($completed / $total * 100) : null],
        ]);
    }
}
