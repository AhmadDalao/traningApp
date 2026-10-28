<?php

namespace App\Http\Controllers;

use App\Models\CoachTraineeRelationship;
use App\Models\ScheduleEntry;
use App\Models\User;
use App\Services\BodyWeightHistory;
use App\Services\CoachAccess;
use App\Services\ExerciseProgress;
use App\Services\TrainingContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CoachController extends Controller
{
    public function setup(Request $request)
    {
        return Inertia::render('Coaching/Setup', ['coachProfile' => $request->user()->coachProfile]);
    }

    public function enable(Request $request)
    {
        $data = $request->validate(['display_name' => 'required|string|max:100', 'specialization' => 'nullable|string|max:150', 'bio' => 'nullable|string|max:2000']);
        DB::transaction(function () use ($request, $data) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            $user->coachProfile()->updateOrCreate([], [...$data, 'active' => true]);
            $user->forceFill(['account_type' => User::COACH])->save();
        });
        $request->session()->put('workspace', 'coach');

        return redirect('/coach')->with('success', 'Coach Workspace is ready. Your personal training is unchanged.');
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

    public function overview(Request $request, ExerciseProgress $progress)
    {
        $relations = CoachTraineeRelationship::where('coach_user_id', $request->user()->id);
        $ids = (clone $relations)->where('status', 'active')->pluck('trainee_user_id');
        $sessions = ScheduleEntry::whereIn('user_id', $ids);
        $upcoming = (clone $sessions)->upcoming()->whereDate('scheduled_date', '<=', today()->addDays(7))->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('id')->limit(12)->get(['id', 'user_id', 'title', 'status', 'entry_type', 'scheduled_date', 'scheduled_time']);
        $recent = (clone $sessions)->whereIn('status', ['completed', 'skipped'])->latest('updated_at')->limit(8)->get(['id', 'user_id', 'title', 'status', 'entry_type', 'updated_at']);
        $names = User::whereIn('id', $ids)->pluck('name', 'id');
        $describe = fn ($entry) => [...$entry->toArray(), 'trainee_name' => $names[$entry->user_id]];
        $records = collect();
        foreach ($recent->where('status', 'completed') as $session) {
            $summary = $progress->forSession(User::findOrFail($session->user_id), $session);
            foreach ($summary['records'] ?? [] as $record) {
                $records->push([...$record, 'trainee_name' => $names[$session->user_id]]);
            }
        }

        return Inertia::render('Coach/Overview', [
            'stats' => ['trainees' => $ids->count(), 'today' => (clone $sessions)->whereDate('scheduled_date', today())->count(), 'pending' => (clone $relations)->where('status', 'pending')->count(), 'upcoming' => (clone $sessions)->upcoming()->whereBetween('scheduled_date', [today()->addDay(), today()->addDays(7)])->count()],
            'upcoming' => $upcoming->map($describe), 'recent' => $recent->map($describe), 'recentRecords' => $records->take(8)->values(),
        ]);
    }

    public function trainees(Request $request, CoachAccess $access)
    {
        $data = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|in:active,pending,ended,all']);
        $status = $data['status'] ?? 'active';
        $query = CoachTraineeRelationship::where('coach_user_id', $request->user()->id)->with('trainee:id,name,training_code', 'subscriptions');
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if (! empty($data['search'])) {
            $query->where('status', 'active')->whereHas('trainee', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%')->orWhere('training_code', 'like', '%'.$data['search'].'%')));
        }

        $relationships = $query->latest()->paginate(12)->withQueryString();
        $active = $relationships->getCollection()->where('status', 'active');
        if ($active->isNotEmpty()) {
            $active->loadMissing('trainee.nextPlannedSessions:id,user_id,title,scheduled_date,scheduled_time', 'trainee.latestCompletedWorkouts:id,user_id,title,scheduled_date,scheduled_time');
        }

        return Inertia::render('Coach/Trainees', ['filters' => ['status' => $status, 'search' => $data['search'] ?? ''], 'relationships' => $relationships->through(function ($row) {
            $active = $row->status === 'active';
            $name = $active ? $row->trainee->name : preg_split('/\s+/u', $row->trainee->name)[0];

            return [...$row->only('id', 'status', 'requested_at', 'approved_at', 'ended_at'), 'trainee' => ['id' => $active ? $row->trainee_user_id : null, 'name' => $name, 'training_code' => $row->trainee->training_code],
                'subscription' => $active ? $row->subscriptions->sortByDesc('id')->first()?->summary() : null,
                'next_session' => $active ? $row->trainee->nextPlannedSessions->first()?->attributesToArray() : null,
                'last_workout' => $active ? $row->trainee->latestCompletedWorkouts->first()?->attributesToArray() : null];
        })]);
    }

    public function trainee(TrainingContext $context, BodyWeightHistory $body, CoachAccess $access, ExerciseProgress $progress)
    {
        $owner = $context->owner();
        $relationship = $access->relationship($context->actor(), $owner);
        $month = $owner->scheduleEntries()->where('entry_type', 'training')->past()->whereBetween('scheduled_date', [today()->startOfMonth(), today()]);
        $total = (clone $month)->count();

        return Inertia::render('Coach/Trainee', [
            'relationship' => $relationship->only('id', 'approved_at'),
            'profile' => $owner->profile?->only('training_goals', 'experience_level', 'training_days_per_week', 'session_duration_minutes', 'equipment_access', 'body_weight_goal'),
            'body' => $body->summary($owner), 'recentRecords' => $progress->overview($owner)['recentRecords'],
            'subscription' => $relationship->subscriptions()->latest()->first()?->summary(),
            'plans' => $context->plans()->latest()->get(['id', 'name', 'start_date', 'end_date']),
            'nextSession' => $owner->nextPlannedSessions()->first(['id', 'title', 'scheduled_date', 'scheduled_time']),
            'recentWorkout' => $owner->latestCompletedWorkouts()->first(['id', 'title', 'scheduled_date']),
            'completion' => $total ? round((clone $month)->where('status', 'completed')->count() / $total * 100) : null,
        ]);
    }
}
