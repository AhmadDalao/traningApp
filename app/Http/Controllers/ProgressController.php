<?php

namespace App\Http\Controllers;

use App\Models\ProgramRun;
use App\Services\BodyWeightHistory;
use App\Services\ExerciseProgress;
use App\Services\ProgramLifecycle;
use App\Services\TrainingContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ProgressController extends Controller
{
    public function __invoke(Request $request, BodyWeightHistory $history, ExerciseProgress $progress, TrainingContext $context)
    {
        $data = $request->validate(['range' => ['nullable', Rule::in(['30', '90', '6m', '1y', 'all'])]]);
        $range = $data['range'] ?? '30';
        $from = match ($range) {
            '30' => today()->subDays(29), '90' => today()->subDays(89),
            '6m' => today()->subMonthsNoOverflow(6), '1y' => today()->subYearNoOverflow(), default => null,
        };
        $user = $context->owner();
        $sessions = $context->sessions()->availableOnSchedule()->where('entry_type', 'training')
            ->whereDate('scheduled_date', '<=', today())->when($from, fn ($q) => $q->whereDate('scheduled_date', '>=', $from));
        $due = (clone $sessions)->due();
        $completed = (clone $due)->where('status', 'completed')->count();
        $skipped = (clone $due)->where('status', 'skipped')->count();
        $total = (clone $due)->count();
        // Consecutive completed due workouts, not an invented calendar-day streak.
        $streak = 0;
        foreach ((clone $due)->orderByDesc('scheduled_date')->orderByDesc('scheduled_time')->orderByDesc('id')->cursor(['status']) as $entry) {
            if ($entry->status !== 'completed') {
                break;
            }
            $streak++;
        }
        $exerciseSummary = $progress->overview($user, range: $range);
        $volume = $progress->query($user)->when($from, fn ($q) => $q->whereDate('session.scheduled_date', '>=', $from))
            ->whereDate('session.scheduled_date', '<=', today())->where('actual.actual_weight_kg', '>', 0)->where('exercise.tracking_mode', 'reps')
            ->select([])->selectRaw('SUM(actual.actual_weight_kg * actual.actual_reps) as volume')->first()->volume;

        return Inertia::render('Progress/Index', [
            'currentTraining' => $context->currentTraining(),
            'range' => $range, 'summary' => $history->summary($user),
            'trend' => $user->bodyWeightEntries()->when($from, fn ($q) => $q->whereDate('recorded_date', '>=', $from))->orderBy('recorded_date')->orderBy('id')->get(['id', 'weight_kg', 'recorded_date']),
            'entries' => $user->bodyWeightEntries()->orderByDesc('recorded_date')->orderByDesc('id')->paginate(10)->withQueryString(),
            'training' => ['workouts_per_week' => round($completed / max(1, ($from ?? Carbon::parse((clone $sessions)->min('scheduled_date') ?? today()))->diffInDays(today()->addDay()) / 7), 1), 'missed' => (clone $due)->where('status', 'planned')->whereNull('started_at')->count(), 'completed' => $completed, 'skipped' => $skipped, 'due' => $total, 'completion_rate' => $total ? round($completed / $total * 100) : null, 'streak' => $streak, 'weighted_volume' => $volume === null ? null : round((float) $volume, 2)],
            'highlights' => $exerciseSummary['highlights'],
            'recentRecords' => $exerciseSummary['recentRecords'], 'recentRecordCount' => $exerciseSummary['recentRecordCount'],
            'recentWorkouts' => (clone $sessions)->where('status', 'completed')->latest('scheduled_date')->latest('scheduled_time')->limit(5)->get(['id', 'title', 'status', 'scheduled_date', 'duration_minutes', 'entry_type', 'workout_snapshot', 'program_prescription']),
            'programRuns' => app(ProgramLifecycle::class)->cards(ProgramRun::where('user_id', $user->id)->whereIn('training_plan_id', $context->plans()->select('training_plans.id'))->whereIn('status', ['active', 'paused'])),
        ]);
    }
}
