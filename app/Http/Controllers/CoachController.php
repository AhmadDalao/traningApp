<?php

namespace App\Http\Controllers;

use App\Models\CoachTraineeRelationship;
use App\Models\ProgramRun;
use App\Models\ScheduleEntry;
use App\Models\User;
use App\Services\BodyWeightHistory;
use App\Services\CoachAccess;
use App\Services\CoachWorkspaceSummary;
use App\Services\ExerciseProgress;
use App\Services\ProgramLifecycle;
use App\Services\TrainingContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CoachController extends Controller
{
    public function setup(Request $request)
    {
        abort_unless($request->user()->isCoach(), 403);

        return Inertia::render('Coaching/Setup', ['coachProfile' => $request->user()->coachProfile]);
    }

    public function updateProfile(Request $request)
    {
        abort_unless($request->user()->isCoach(), 403);
        $data = $request->validate(['display_name' => 'required|string|max:100', 'specialization' => 'nullable|string|max:150', 'bio' => 'nullable|string|max:2000']);
        DB::transaction(function () use ($request, $data) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($user->isCoach(), 403);
            $user->coachProfile()->firstOrFail()->update($data);
        });
        $request->session()->put('workspace', 'coach');

        return redirect('/coach')->with('success', 'Coach profile updated.');
    }

    public function switch(Request $request)
    {
        $data = $request->validate(['workspace' => 'required|in:personal,coach']);
        abort_if($data['workspace'] === 'coach' && ! $request->user()->isCoach(), 403);
        $request->session()->put('workspace', $data['workspace']);
        if ($data['workspace'] === 'personal' && (! $request->user()->profile || $request->user()->profile->needsOnboarding())) {
            $request->session()->put('show_onboarding', true);
        }

        return redirect($data['workspace'] === 'coach' ? '/coach' : '/dashboard');
    }

    public function overview(Request $request, CoachWorkspaceSummary $summary)
    {
        $query = $summary->query($request->user());
        $counts = (clone $query)->select('display_state')->selectRaw('COUNT(*) as total')->groupBy('display_state')->pluck('total', 'display_state');
        $ids = CoachTraineeRelationship::where('coach_user_id', $request->user()->id)->where('status', 'active')->select('trainee_user_id');
        $sessions = ScheduleEntry::whereIn('user_id', $ids)->availableOnSchedule();
        $describe = fn ($entry) => [...$entry->toArray(), 'trainee_name' => $entry->user->name];
        $upcoming = (clone $sessions)->upcoming()->whereNull('started_at')->whereDate('scheduled_date', '<=', today()->addDays(7))->with('user:id,name')->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('id')->limit(12)->get(['id', 'user_id', 'title', 'status', 'entry_type', 'scheduled_date', 'scheduled_time']);
        $recent = (clone $sessions)->whereIn('status', ['completed', 'skipped'])->with('user:id,name')->latest('updated_at')->limit(8)->get(['id', 'user_id', 'title', 'status', 'entry_type', 'updated_at']);

        return Inertia::render('Coach/Overview', [
            'stats' => ['trainees' => array_sum($counts->except(['pending', 'ended'])->all()), 'today' => (clone $sessions)->whereDate('scheduled_date', today())->where('entry_type', 'training')->whereIn('status', ['planned', 'completed'])->distinct()->count('user_id'), 'completed' => (clone $sessions)->whereDate('scheduled_date', today())->where('entry_type', 'training')->where('status', 'completed')->count(), 'attention' => ($counts['attention'] ?? 0) + ($counts['setup'] ?? 0) + ($counts['paused'] ?? 0), 'pending' => $counts['pending'] ?? 0, 'upcoming' => (clone $sessions)->upcoming()->whereNull('started_at')->whereBetween('scheduled_date', [today()->addDay(), today()->addDays(7)])->count()],
            'attention' => $summary->rows((clone $query)->whereIn('display_state', ['attention', 'setup', 'paused'])->orderByRaw("CASE display_state WHEN 'attention' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")->orderBy('id')->limit(8)->get()),
            'pending' => $summary->rows((clone $query)->where('status', 'pending')->orderByDesc('id')->limit(6)->get()),
            'upcoming' => $upcoming->map($describe), 'recent' => $recent->map($describe),
        ]);
    }

    public function trainees(Request $request, CoachWorkspaceSummary $summary)
    {
        $data = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|in:active,pending,ended,all,setup,attention,paused']);
        $status = $data['status'] ?? 'all';
        $query = $summary->query($request->user());
        $counts = (clone $query)->select('display_state')->selectRaw('COUNT(*) as total')->groupBy('display_state')->pluck('total', 'display_state');
        if ($status !== 'all') {
            $query->where('display_state', $status);
        }
        if (! empty($data['search'])) {
            $query->whereIn('trainee_user_id', User::select('id')->where(fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%')->orWhere('training_code', 'like', '%'.$data['search'].'%')))->where('status', 'active');
        }
        $relationships = $query->orderByDesc('id')->paginate(25)->withQueryString();
        $relationships->setCollection(collect($summary->rows($relationships->items())));

        return Inertia::render('Coach/Trainees', ['counts' => $counts, 'filters' => ['status' => $status, 'search' => $data['search'] ?? ''], 'relationships' => $relationships]);
    }

    public function trainee(TrainingContext $context, BodyWeightHistory $body, CoachAccess $access, ExerciseProgress $progress)
    {
        $owner = $context->owner();
        $relationship = $access->relationship($context->actor(), $owner);
        $month = $owner->scheduleEntries()->availableOnSchedule()->where('entry_type', 'training')->past()->whereBetween('scheduled_date', [today()->startOfMonth(), today()]);
        $total = (clone $month)->count();

        return Inertia::render('Coach/Trainee', [
            'state' => app(CoachWorkspaceSummary::class)->one($context->actor(), $owner),
            'thisWeek' => $owner->scheduleEntries()->availableOnSchedule()->whereBetween('scheduled_date', [today()->startOfWeek(), today()->endOfWeek()])->orderBy('scheduled_date')->orderBy('scheduled_time')->get(['id', 'title', 'entry_type', 'status', 'started_at', 'scheduled_date', 'scheduled_time'])->map(fn ($entry) => [...$entry->toArray(), 'missed' => $entry->entry_type === 'training' && $entry->status === 'planned' && ! $entry->started_at && ! $access->future($entry->scheduled_date->toDateString(), $entry->scheduled_time)]),
            'programRuns' => app(ProgramLifecycle::class)->cards(ProgramRun::where('user_id', $owner->id)->whereIn('training_plan_id', $context->plans()->select('training_plans.id'))),
            'relationship' => $relationship->only('id', 'approved_at'),
            'profile' => $owner->profile?->only('training_goals', 'experience_level', 'training_days_per_week', 'session_duration_minutes', 'equipment_access', 'body_weight_goal'),
            'body' => $body->summary($owner), 'recentRecords' => $progress->overview($owner)['recentRecords'],
            'subscription' => $relationship->subscriptions()->latest()->first()?->summary(),
            'plans' => $context->plans()->latest()->get(['id', 'name', 'start_date', 'end_date']),
            'nextSession' => $owner->nextPlannedSessions()->whereNull('started_at')->first(['id', 'title', 'scheduled_date', 'scheduled_time']),
            'recentWorkout' => $owner->latestCompletedWorkouts()->first(['id', 'title', 'scheduled_date']),
            'completion' => $total ? round((clone $month)->where('status', 'completed')->count() / $total * 100) : null,
        ]);
    }
}
