<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveProfileRequest;
use App\Models\NotificationPreference;
use App\Models\ProgramRun;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\WebPushSubscription;
use App\Services\BodyWeightHistory;
use App\Services\CoachAccess;
use App\Services\CoachWorkspaceSummary;
use App\Services\ExerciseProgress;
use App\Services\Notifications\PushDevices;
use App\Services\Notifications\PushTransport;
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

        if (! $request->is('coach/settings')) {
            return Inertia::render('Coaching/Setup', ['coachProfile' => $request->user()->coachProfile, 'settings' => false]);
        }
        $devices = app(PushDevices::class);
        $push = app(PushTransport::class);
        $response = Inertia::render('Coach/Settings', [
            'coachProfile' => $request->user()->coachProfile,
            'profile' => $request->user()->profile,
            'options' => UserProfile::options(),
            'preferences' => NotificationPreference::settings($request->user()->id),
            'deviceEnabled' => (bool) $devices->current($request),
            'deviceCount' => WebPushSubscription::where('user_id', $request->user()->id)->count(),
            'pushConfigured' => $push->configured(),
            'publicKey' => $push->configured() ? config('webpush.vapid.publicKey') : null,
        ])->toResponse($request);
        if (! $devices->hash($request)) {
            $response->withCookie(cookie(PushDevices::COOKIE, bin2hex(random_bytes(32)), 525600, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response->header('Cache-Control', 'private, no-store');
    }

    public function updateProfile(Request $request)
    {
        abort_unless($request->user()->isCoach(), 403);
        $rules = ['display_name' => 'required|string|max:100', 'specialization' => 'nullable|string|max:150', 'bio' => 'nullable|string|max:2000'];
        $preferenceKeys = ['training_goals', 'body_weight_goal', 'target_body_weight_kg', 'experience_level', 'training_days_per_week', 'session_duration_minutes', 'equipment_access'];
        $rules['training_preferences'] = 'sometimes|array:'.implode(',', $preferenceKeys);
        foreach ((new SaveProfileRequest)->rules() as $key => $rule) {
            if (in_array(explode('.', $key)[0], $preferenceKeys, true)) {
                $rules['training_preferences.'.$key] = $rule;
            }
        }
        $rules['notifications'] = 'sometimes|array:workout_reminders,coach_updates,reminder_minutes';
        $rules['notifications.workout_reminders'] = 'required_with:notifications|boolean';
        $rules['notifications.coach_updates'] = 'required_with:notifications|boolean';
        $rules['notifications.reminder_minutes'] = 'required_with:notifications|integer|in:15,30,60,120';
        $data = $request->validate($rules);
        DB::transaction(function () use ($request, $data) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($user->isCoach(), 403);
            $user->coachProfile()->firstOrFail()->update(collect($data)->only(['display_name', 'specialization', 'bio'])->all());
            if (isset($data['training_preferences'])) {
                $preferences = $data['training_preferences'];
                $profile = $user->profile()->firstOrCreate();
                if (array_key_exists('training_goals', $preferences)) {
                    $preferences['training_goals'] ??= [];
                    $preferences['training_goal'] = $preferences['training_goals'][0] ?? null;
                }
                if (! in_array(array_key_exists('body_weight_goal', $preferences) ? $preferences['body_weight_goal'] : $profile->body_weight_goal, ['lose', 'gain'], true)) {
                    $preferences['target_body_weight_kg'] = null;
                }
                $profile->update($preferences);
            }
            if (isset($data['notifications'])) {
                NotificationPreference::updateOrCreate(['user_id' => $user->id], $data['notifications']);
            }
        });
        $request->session()->put('workspace', 'coach');

        return redirect($request->is('coach/settings') ? '/coach/settings' : '/coach')->with('success', isset($data['training_preferences']) || isset($data['notifications']) ? 'Settings saved.' : 'Coach profile updated.');
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
        $recent = (clone $sessions)->whereIn('status', ['completed', 'skipped'])->with('user:id,name')->latest('updated_at')->limit(100)->get(['id', 'user_id', 'title', 'status', 'entry_type', 'scheduled_date', 'scheduled_time', 'updated_at', 'training_day_id', 'workout_snapshot', 'program_prescription']);

        $weekStart = today()->startOfWeek();
        $weekly = (clone $sessions)->where('entry_type', 'training')->whereBetween('scheduled_date', [$weekStart, $weekStart->copy()->endOfWeek()])->reorder()->selectRaw("DATE(scheduled_date) as day, COUNT(*) as scheduled, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")->groupByRaw('DATE(scheduled_date)')->get()->keyBy('day');
        $workload = collect(range(0, 6))->map(function ($offset) use ($weekStart, $weekly) {
            $day = $weekStart->copy()->addDays($offset)->toDateString();

            return ['date' => $day, 'scheduled' => (int) ($weekly[$day]->scheduled ?? 0), 'completed' => (int) ($weekly[$day]->completed ?? 0)];
        });

        return Inertia::render('Coach/Overview', [
            'workload' => $workload,
            'momentum' => $summary->rows((clone $query)->where('status', 'active')->orderBy('id')->limit(100)->get()),
            'stats' => ['trainees' => array_sum($counts->except(['pending', 'ended'])->all()), 'today' => (clone $sessions)->whereDate('scheduled_date', today())->where('entry_type', 'training')->whereIn('status', ['planned', 'completed'])->distinct()->count('user_id'), 'completed' => (clone $sessions)->whereDate('scheduled_date', today())->where('entry_type', 'training')->where('status', 'completed')->count(), 'attention' => ($counts['attention'] ?? 0) + ($counts['setup'] ?? 0) + ($counts['paused'] ?? 0), 'pending' => $counts['pending'] ?? 0, 'upcoming' => (clone $sessions)->upcoming()->whereNull('started_at')->whereBetween('scheduled_date', [today()->addDay(), today()->addDays(7)])->count()],
            'attention' => $summary->rows((clone $query)->whereIn('display_state', ['attention', 'setup', 'paused'])->orderByRaw("CASE display_state WHEN 'attention' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")->orderBy('id')->limit(100)->get()),
            'pending' => $summary->rows((clone $query)->where('status', 'pending')->orderByDesc('id')->limit(6)->get()),
            'todaySessions' => $todaySessions->map($describe),
            'upcomingGroups' => $groups->map(fn ($group) => [...$describe($nextEntries[$group->user_id]), 'session_count' => (int) $group->session_count])->sortBy(fn ($row) => $row['scheduled_date'].' '.$row['scheduled_time'])->values(),
            'upcoming' => $upcoming->map($describe), 'recent' => $recent->map($describe),
        ]);
    }

    public function trainees(Request $request, CoachWorkspaceSummary $summary)
    {
        $data = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|in:active,coaching,review,pending,ended,all,setup,attention,paused', 'sort' => 'nullable|in:newest,name', 'per_page' => 'nullable|in:10,25,50,100']);
        $status = $data['status'] ?? 'all';
        $query = $summary->query($request->user());
        $counts = (clone $query)->select('display_state')->selectRaw('COUNT(*) as total')->groupBy('display_state')->pluck('total', 'display_state');
        $covered = fn ($q) => $q->where('status', 'active')->where('subscription_status', 'active')->where('starts_on', '<=', today()->toDateString())->where(fn ($dates) => $dates->whereNull('ends_on')->orWhere('ends_on', '>=', today()->toDateString()));
        $rosterStats = ['connected' => (clone $query)->where('status', 'active')->count(), 'coaching' => $covered(clone $query)->count(), 'review' => ($counts['attention'] ?? 0) + ($counts['setup'] ?? 0) + ($counts['paused'] ?? 0)];
        if ($status === 'coaching') {
            $covered($query);
        } elseif ($status === 'review') {
            $query->whereIn('display_state', ['attention', 'setup', 'paused']);
        } elseif ($status !== 'all') {
            $query->where('display_state', $status);
        }
        if (! empty($data['search'])) {
            $query->where(fn ($q) => $q->whereIn('trainee_user_id', User::select('id')->where(fn ($u) => $u->where('name', 'like', '%'.$data['search'].'%')->orWhere('training_code', 'like', '%'.$data['search'].'%')))->orWhere('plan_name', 'like', '%'.$data['search'].'%'))->where('status', 'active');
        }
        $relationships = $query->when(($data['sort'] ?? 'newest') === 'name', fn ($q) => $q->orderBy(User::select('name')->whereColumn('users.id', 'summaries.trainee_user_id')->limit(1)))->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString();
        $relationships->setCollection(collect($summary->rows($relationships->items())));

        return Inertia::render('Coach/Trainees', ['rosterStats' => $rosterStats, 'counts' => $counts, 'filters' => ['status' => $status, 'search' => $data['search'] ?? '', 'sort' => $data['sort'] ?? 'newest', 'per_page' => (int) ($data['per_page'] ?? 25)], 'relationships' => $relationships]);
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
            'plans' => $context->plans()->withThumbnail()->with('assignment.subscription', 'programRuns')->latest()->get(['id', 'name', 'structure_type', 'duration_weeks', 'start_date', 'end_date'])->map(fn ($plan) => [...$plan->toArray(), 'subscription' => $plan->assignment?->subscription?->summary()]),
            'nextSession' => $context->sessions()->withThumbnail()->upcoming()->orderBy('scheduled_date')->orderBy('scheduled_time')->whereNull('started_at')->first(),
            'recentWorkout' => $context->sessions()->where('entry_type', 'training')->where('status', 'completed')->latest('scheduled_date')->latest('scheduled_time')->first(['id', 'title', 'scheduled_date', 'entry_type', 'status', 'workout_snapshot', 'program_prescription']),
            'completion' => $total ? round((clone $month)->where('status', 'completed')->count() / $total * 100) : null,
        ]);
    }
}
