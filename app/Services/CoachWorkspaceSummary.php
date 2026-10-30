<?php

namespace App\Services;

use App\Models\CoachPlanAssignment;
use App\Models\CoachSubscription;
use App\Models\CoachTraineeRelationship;
use App\Models\ProgramRun;
use App\Models\ScheduleEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Lightweight, derived operational state. No workout history or performance sets are loaded. */
class CoachWorkspaceSummary
{
    public const ENDING_SOON_DAYS = 7;

    public function query(User $coach)
    {
        $subscriptions = CoachSubscription::select('id')->whereColumn('coach_trainee_relationship_id', 'coach_trainee_relationships.id')->latest('id')->limit(1);
        $assignments = CoachPlanAssignment::select('id')->whereColumn('coach_trainee_relationship_id', 'coach_trainee_relationships.id')->where('status', 'active')->whereHas('plan')->latest('id')->limit(1);
        $base = CoachTraineeRelationship::where('coach_user_id', $coach->id)->select('coach_trainee_relationships.*')
            ->selectSub($subscriptions, 'subscription_id')->selectSub($assignments, 'assignment_id');
        $facts = DB::query()->fromSub($base, 'r')
            ->leftJoin('coach_subscriptions as s', 's.id', '=', 'r.subscription_id')
            ->leftJoin('coach_plan_assignments as a', 'a.id', '=', 'r.assignment_id')
            ->leftJoin('training_plans as p', 'p.id', '=', 'a.assigned_training_plan_id')
            ->select('r.*', 'p.id as plan_id', 'p.name as plan_name', 'p.structure_type', 'p.duration_weeks', 's.package_name', 's.status as subscription_status')
            ->selectRaw('DATE(s.starts_on) as starts_on, DATE(s.ends_on) as ends_on')
            ->selectSub(ProgramRun::select('id')->whereColumn('training_plan_id', 'p.id')->latest('id')->limit(1), 'run_id');
        $sessions = ScheduleEntry::whereColumn('schedule_entries.user_id', 'f.trainee_user_id')->where('scheduled_by_user_id', $coach->id)
            ->whereHas('trainingDay', fn ($q) => $q->whereColumn('training_plan_id', 'f.plan_id'));
        $available = (clone $sessions)->availableOnSchedule();
        $flags = DB::query()->fromSub($facts, 'f')->leftJoin('program_runs as run', 'run.id', '=', 'f.run_id')->select('f.*', 'run.status as run_status')
            ->selectSub((clone $sessions)->selectRaw('COUNT(*)'), 'schedule_count')
            ->selectSub((clone $available)->upcoming()->whereNull('started_at')->selectRaw('COUNT(*)'), 'upcoming_count')
            ->selectSub((clone $available)->where('entry_type', 'training')->where('status', 'planned')->whereNull('started_at')->past()->selectRaw('COUNT(*)'), 'missed_count')
            ->selectSub(ScheduleEntry::whereColumn('user_id', 'f.trainee_user_id')->upcoming()->whereNull('started_at')->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('id')->select('id')->limit(1), 'next_id')
            ->selectSub(ScheduleEntry::whereColumn('user_id', 'f.trainee_user_id')->where('entry_type', 'training')->where('status', 'completed')->latest('scheduled_date')->latest('scheduled_time')->latest('id')->select('id')->limit(1), 'last_id')
            ->selectSub((clone $available)->where('entry_type', 'training')->whereDate('scheduled_date', '>=', today()->startOfWeek())->whereDate('scheduled_date', '<=', today()->endOfWeek())->selectRaw('COUNT(*)'), 'week_total')
            ->selectSub((clone $available)->where('entry_type', 'training')->where('status', 'completed')->whereDate('scheduled_date', '>=', today()->startOfWeek())->whereDate('scheduled_date', '<=', today()->endOfWeek())->selectRaw('COUNT(*)'), 'week_completed')
            ->selectSub(ScheduleEntry::whereColumn('program_run_id', 'f.run_id')->where('entry_type', 'training')->whereNotIn('status', ['completed', 'skipped'])->selectRaw('MIN(program_week)'), 'current_week');
        $states = DB::query()->fromSub($flags, 'facts')->select('facts.*')->selectRaw("CASE
            WHEN status <> 'active' THEN status
            WHEN subscription_status = 'paused' OR run_status = 'paused' THEN 'paused'
            WHEN subscription_id IS NULL OR subscription_status <> 'active' OR starts_on > ? OR (ends_on IS NOT NULL AND ends_on < ?) OR plan_id IS NULL OR (structure_type = 'program' AND run_id IS NULL) OR (structure_type <> 'program' AND schedule_count = 0) THEN 'setup'
            WHEN missed_count > 0 OR run_status = 'ended' OR (ends_on IS NOT NULL AND ends_on <= ?) OR (upcoming_count = 0 AND (run_status IS NULL OR run_status <> 'completed')) THEN 'attention'
            ELSE 'active' END as display_state", [today()->toDateString(), today()->toDateString(), today()->addDays(self::ENDING_SOON_DAYS)->toDateString()]);

        return DB::query()->fromSub($states, 'summaries');
    }

    public function rows($rows): array
    {
        $rows = collect($rows);
        $users = User::whereIn('id', $rows->pluck('trainee_user_id'))->get(['id', 'name', 'training_code'])->keyBy('id');
        $entries = ScheduleEntry::whereIn('id', $rows->pluck('next_id')->merge($rows->pluck('last_id'))->filter())->get(['id', 'title', 'scheduled_date', 'scheduled_time', 'completed_at'])->keyBy('id');

        return $rows->map(function ($r) use ($users, $entries) {
            $user = $users[$r->trainee_user_id];
            $active = $r->status === 'active';
            $prefix = '/coach/trainees/'.$user->id;
            $reason = match (true) {
                ! $active => ucfirst($r->status).' connection',
                $r->subscription_status === 'paused' => 'Coaching paused',
                $r->run_status === 'paused' => 'Program paused',
                ! $r->subscription_id => 'Coaching not configured',
                $r->subscription_status !== 'active' || ($r->ends_on && $r->ends_on < today()->toDateString()) => 'Coaching ended or expired',
                $r->starts_on > today()->toDateString() => 'Coaching starts '.$r->starts_on,
                ! $r->plan_id => 'Training not assigned',
                $r->structure_type === 'program' && ! $r->run_id => 'Program not started',
                ! $r->schedule_count => 'Schedule not configured',
                $r->missed_count > 0 => $r->missed_count.' missed workout'.($r->missed_count == 1 ? '' : 's'),
                $r->run_status === 'ended' => 'Program ended',
                $r->ends_on && $r->ends_on <= today()->addDays(self::ENDING_SOON_DAYS)->toDateString() => 'Coaching ends '.$r->ends_on,
                $r->run_status === 'completed' => 'Program completed',
                ! $r->upcoming_count => 'No upcoming workout',
                default => 'Training is on track',
            };
            $action = match ($r->display_state) {
                'setup' => ['label' => 'Continue setup', 'url' => $prefix.'/setup'],
                'paused' => ['label' => 'Review pause', 'url' => $r->subscription_status === 'paused' ? $prefix.'/coaching' : $prefix.'/program-runs/'.$r->run_id],
                'attention' => ['label' => 'Review', 'url' => $prefix],
                default => ['label' => 'View trainee', 'url' => $prefix],
            };
            $subscription = $active && $r->subscription_id ? ['id' => $r->subscription_id, 'package_name' => $r->package_name, 'status' => $r->subscription_status, 'effective_status' => $r->subscription_status !== 'active' ? $r->subscription_status : ($r->starts_on > today()->toDateString() ? 'upcoming' : ($r->ends_on && $r->ends_on < today()->toDateString() ? 'expired' : 'active')), 'starts_on' => $r->starts_on, 'ends_on' => $r->ends_on] : null;

            return ['id' => $r->id, 'status' => $r->status, 'display_state' => $r->display_state,
                'trainee' => ['id' => $active ? $user->id : null, 'name' => $active ? $user->name : preg_split('/\s+/u', $user->name)[0], 'training_code' => $user->training_code],
                'subscription' => $subscription, 'plan' => $active && $r->plan_id ? ['id' => $r->plan_id, 'name' => $r->plan_name, 'structure_type' => $r->structure_type, 'duration_weeks' => $r->duration_weeks, 'run_id' => $r->run_id, 'run_status' => $r->run_status, 'current_week' => $r->current_week] : null,
                'has_schedule' => $active && ($r->run_id || $r->schedule_count > 0),
                'reason' => $reason, 'action' => $active ? $action : null,
                'next_session' => $active ? ($entries[$r->next_id] ?? null)?->toArray() : null,
                'last_workout' => $active ? ($entries[$r->last_id] ?? null)?->toArray() : null,
                'week' => ['completed' => $active ? (int) $r->week_completed : 0, 'total' => $active ? (int) $r->week_total : 0]];
        })->all();
    }

    public function one(User $coach, User $trainee): array
    {
        $key = 'coach_summary_'.$coach->id.'_'.$trainee->id;
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, $this->rows($this->query($coach)->where('trainee_user_id', $trainee->id)->where('status', 'active')->get())[0]);
        }

        return request()->attributes->get($key);
    }
}
