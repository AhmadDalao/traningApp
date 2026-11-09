<?php

namespace App\Http\Controllers;

use App\Models\ProgramRun;
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

        return Inertia::render('Coaching/Setup', ['coachProfile' => $request->user()->coachProfile, 'settings' => $request->is('coach/settings')]);
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

        return redirect($request->is('coach/settings') ? '/coach/settings' : '/coach')->with('success', 'Coach profile updated.');
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
        $sessions = app(CoachAccess::class)->sessions($request->user())->availableOnSchedule()->withThumbnail();
        $describe = fn ($entry) => [...$entry->only('id', 'user_id', 'title', 'status', 'entry_type', 'scheduled_date', 'scheduled_time', 'updated_at', 'thumbnail_url'), 'scheduled_date' => $entry->scheduled_date?->toDateString(), 'trainee_name' => $entry->user->name];
        $upcoming = (clone $sessions)->upcoming()->whereNull('started_at')->whereDate('scheduled_date', '<=', today()->addDays(7))->with('user:id,name')->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('id')->limit(12)->get(['id', 'user_id', 'title', 'status', 'entry_type', 'scheduled_date', 'scheduled_time', 'training_day_id', 'workout_snapshot', 'program_prescription']);
        $nextWeek = (clone $sessions)->upcoming()->whereNull('started_at')->whereBetween('scheduled_date', [today()->addDay(), today()->addDays(7)]);
        $groups = (clone $nextWeek)->reorder()->select('user_id')->selectRaw('COUNT(*) as session_count')->groupBy('user_id')->get();
        $firstIds = (clone $nextWeek)->reorder()->select('id')->selectRaw('ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY scheduled_date, scheduled_time, id) as sequence');
        $nextEntries = (clone $sessions)->whereIn('id', DB::query()->fromSub($firstIds, 'ranked')->where('sequence', 1)->select('id'))->with('user:id,name')->orderBy('scheduled_date')->orderBy('scheduled_time')->get()->keyBy('user_id');
        $todaySessions = (clone $sessions)->whereDate('scheduled_date', today())->with('user:id,name')->orderBy('user_id')->orderBy('scheduled_time')->get();
        $recent = (clone $sessions)->whereIn('status', ['completed', 'skipped'])->with('user:id,name')->latest('updated_at')->limit(8)->get(['id', 'user_id', 'title', 'status', 'entry_type', 'scheduled_date', 'scheduled_time', 'updated_at', 'training_day_id', 'workout_snapshot', 'program_prescription']);

        return Inertia::render('Coach/Overview', [
            'stats' => ['trainees' => array_sum($counts->except(['pending', 'ended'])->all()), 'today' => (clone $sessions)->whereDate('scheduled_date', today())->where('entry_type', 'training')->whereIn('status', ['planned', 'completed'])->distinct()->count('user_id'), 'completed' => (clone $sessions)->whereDate('scheduled_date', today())->where('entry_type', 'training')->where('status', 'completed')->count(), 'attention' => ($counts['attention'] ?? 0) + ($counts['setup'] ?? 0) + ($counts['paused'] ?? 0), 'pending' => $counts['pending'] ?? 0, 'upcoming' => (clone $sessions)->upcoming()->whereNull('started_at')->whereBetween('scheduled_date', [today()->addDay(), today()->addDays(7)])->count()],
            'attention' => $summary->rows((clone $query)->whereIn('display_state', ['attention', 'setup', 'paused'])->orderByRaw("CASE display_state WHEN 'attention' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")->orderBy('id')->limit(8)->get()),
            'pending' => $summary->rows((clone $query)->where('status', 'pending')->orderByDesc('id')->limit(6)->get()),
            'todaySessions' => $todaySessions->map($describe),
            'upcomingGroups' => $groups->map(fn ($group) => [...$describe($nextEntries[$group->user_id]), 'session_count' => (int) $group->session_count])->sortBy(fn ($row) => $row['scheduled_date'].' '.$row['scheduled_time'])->values(),
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
        $month = $context->sessions()->availableOnSchedule()->where('entry_type', 'training')->due()->whereDate('scheduled_date', '>=', today()->startOfMonth())->whereDate('scheduled_date', '<=', today());
        $total = (clone $month)->count();

        return Inertia::render('Coach/Trainee', [
            'currentTraining' => $context->currentTraining(),
            'recentActivity' => $context->sessions()->withThumbnail()->whereIn('status', ['completed', 'skipped'])->latest('updated_at')->limit(5)->get(),
            'state' => app(CoachWorkspaceSummary::class)->one($context->actor(), $owner),
            'thisWeek' => $context->sessions()->availableOnSchedule()->whereBetween('scheduled_date', [today()->startOfWeek(), today()->endOfWeek()])->orderBy('scheduled_date')->orderBy('scheduled_time')->get(['id', 'title', 'entry_type', 'status', 'started_at', 'scheduled_date', 'scheduled_time'])->map(fn ($entry) => [...$entry->toArray(), 'missed' => $entry->entry_type === 'training' && $entry->status === 'planned' && ! $entry->started_at && ! $access->future($entry->scheduled_date->toDateString(), $entry->scheduled_time)]),
            'programRuns' => app(ProgramLifecycle::class)->cards(ProgramRun::where('user_id', $owner->id)->whereIn('training_plan_id', $context->plans()->select('training_plans.id'))),
            'relationship' => $relationship->only('id', 'approved_at'),
            'profile' => $owner->profile?->only('training_goals', 'experience_level', 'training_days_per_week', 'session_duration_minutes', 'equipment_access', 'body_weight_goal'),
            'body' => $body->summary($owner), 'recentRecords' => $progress->overview($owner)['recentRecords'],
            'subscription' => $relationship->subscriptions()->latest()->first()?->summary(),
            'plans' => $context->plans()->withThumbnail()->with('assignment', 'programRuns')->latest()->get(['id', 'name', 'structure_type', 'duration_weeks', 'start_date', 'end_date']),
            'nextSession' => $context->sessions()->withThumbnail()->upcoming()->orderBy('scheduled_date')->orderBy('scheduled_time')->whereNull('started_at')->first(),
            'recentWorkout' => $context->sessions()->where('entry_type', 'training')->where('status', 'completed')->latest('scheduled_date')->latest('scheduled_time')->first(['id', 'title', 'scheduled_date', 'entry_type', 'status', 'workout_snapshot', 'program_prescription']),
            'completion' => $total ? round((clone $month)->where('status', 'completed')->count() / $total * 100) : null,
        ]);
    }
}
