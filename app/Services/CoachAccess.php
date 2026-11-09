<?php

namespace App\Services;

use App\Models\CoachPlanAssignment;
use App\Models\CoachSubscription;
use App\Models\CoachTraineeRelationship;
use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CoachAccess
{
    /** The trainee owns the copy; the accepted assignment determines Coach visibility. */
    public function plans(User $coach, ?User $trainee = null, bool $manage = false)
    {
        abort_unless($coach->isCoach(), 403);

        return TrainingPlan::where('workspace_type', 'coach_assigned')
            ->when($trainee, fn ($q) => $q->where('user_id', $trainee->id))
            ->whereHas('assignment', fn ($q) => $q
                ->when($manage, fn ($q) => $q->where('status', 'active')->whereHas('subscription', fn ($s) => $s->whereColumn('coach_subscriptions.coach_trainee_relationship_id', 'coach_plan_assignments.coach_trainee_relationship_id')->where('status', 'active')->whereDate('starts_on', '<=', today())->where(fn ($s) => $s->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))))
                ->whereHas('relationship', fn ($q) => $q->where('status', 'active')
                    ->where('coach_user_id', $coach->id)
                    ->whereColumn('trainee_user_id', 'training_plans.user_id')));
    }

    /** Include owner-scheduled sessions of an assigned copy, never unrelated workouts. */
    public function sessions(User $coach, ?User $trainee = null)
    {
        $plans = $this->plans($coach, $trainee)->select('training_plans.id');

        return ScheduleEntry::query()->when($trainee, fn ($q) => $q->where('user_id', $trainee->id))
            ->where(fn ($q) => $q
                ->whereHas('trainingDay', fn ($q) => $q->whereIn('training_plan_id', clone $plans)
                    ->whereHas('trainingPlan', fn ($q) => $q->whereColumn('training_plans.user_id', 'schedule_entries.user_id')))
                ->orWhereHas('programRun', fn ($q) => $q->whereIn('training_plan_id', clone $plans)->whereColumn('user_id', 'schedule_entries.user_id'))
                ->orWhere(fn ($q) => $q->whereNull('training_day_id')->whereNull('program_run_id')
                    ->whereIn('workout_snapshot->training_plan_id', (clone $plans)->whereColumn('training_plans.user_id', 'schedule_entries.user_id'))));
    }

    public function relationship(User $coach, User $trainee, bool $lock = false): CoachTraineeRelationship
    {
        abort_unless($coach->isCoach() && $coach->id !== $trainee->id, 403);

        return CoachTraineeRelationship::where('coach_user_id', $coach->id)->where('trainee_user_id', $trainee->id)
            ->where('status', 'active')->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    public function subscription(CoachTraineeRelationship $relationship, bool $lock = false): ?CoachSubscription
    {
        return $relationship->subscriptions()->where('status', 'active')->whereDate('starts_on', '<=', today())
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    public function subscriptionFor(CoachPlanAssignment $assignment, bool $lock = false): ?CoachSubscription
    {
        return $assignment->relationship->subscriptions()->whereKey($assignment->coach_subscription_id)
            ->where('status', 'active')->whereDate('starts_on', '<=', today())
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    public function assignment(User $coach, User $trainee, TrainingPlan $plan, bool $manage = false, bool $lock = false): CoachPlanAssignment
    {
        $relationship = $this->relationship($coach, $trainee, $lock);
        abort_unless($plan->user_id === $trainee->id && $plan->workspace_type === 'coach_assigned', 403);
        $assignment = $relationship->assignments()->where('assigned_training_plan_id', $plan->id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        $assignment->setRelation('relationship', $relationship);
        if ($manage) {
            abort_unless($assignment->status === 'active' && $this->subscriptionFor($assignment, $lock), 403, 'An active coaching subscription and assignment are required.');
        }

        return $assignment->setRelation('relationship', $relationship);
    }

    public function futureSession(User $coach, User $trainee, ScheduleEntry $entry): void
    {
        abort_unless($entry->user_id === $trainee->id && $entry->scheduled_by_user_id === $coach->id, 403);
        $plan = $entry->trainingDay?->trainingPlan ?? $entry->programRun?->trainingPlan;
        abort_unless($plan, 403);
        $assignment = $this->assignment($coach, $trainee, $plan, true);
        $subscription = $this->subscriptionFor($assignment);
        abort_unless($subscription, 403, 'An active coaching subscription is required.');
        $this->futureAssignedSession($entry, $assignment, $subscription);
    }

    /** Reuse one freshly authorized assignment during a locked bulk review. No global permission cache. */
    public function futureAssignedSession(ScheduleEntry $entry, CoachPlanAssignment $assignment, CoachSubscription $subscription): void
    {
        $relationship = $assignment->relationship;
        abort_unless($relationship->status === 'active' && $assignment->status === 'active'
            && $subscription->id === $assignment->coach_subscription_id
            && $subscription->coach_trainee_relationship_id === $relationship->id && $subscription->status === 'active'
            && $subscription->starts_on->lte(today()) && (! $subscription->ends_on || $subscription->ends_on->gte(today())), 403);
        abort_unless($entry->user_id === $relationship->trainee_user_id && $entry->scheduled_by_user_id === $relationship->coach_user_id
            && ($entry->trainingDay?->training_plan_id ?? $entry->programRun?->training_plan_id) === $assignment->assigned_training_plan_id, 403);
        abort_if($entry->programRun?->holds($entry) ?? false, 403, 'Resume the program before managing its sessions.');
        abort_unless($entry->status === 'planned' && ! $entry->started_at && $entry->workout_snapshot === null
            && ! ($entry->session_exercises_exists ?? $entry->sessionExercises()->exists()), 403, 'Saved and active workouts are protected.');
        abort_unless($this->future($entry->scheduled_date->toDateString(), $entry->scheduled_time), 403, 'Past sessions are protected.');
        $this->scheduleDate($subscription, $entry->scheduled_date->toDateString(), $entry->scheduled_time);
    }

    public function canManageSession(User $coach, User $trainee, ScheduleEntry $entry): bool
    {
        try {
            $this->futureSession($coach, $trainee, $entry);

            return true;
        } catch (HttpExceptionInterface|ModelNotFoundException|ValidationException $error) {
            return false;
        }
    }

    public function future(string $date, ?string $time): bool
    {
        return ! app(WorkoutDate::class)->elapsed($date, $time);
    }

    public function scheduleDate(CoachSubscription $subscription, string $date, ?string $time): void
    {
        Validator::make(['scheduled_date' => $date], ['scheduled_date' => [function ($attribute, $value, $fail) use ($subscription, $time) {
            if (! $this->future($value, $time) || $value < $subscription->starts_on->toDateString() || ($subscription->ends_on && $value > $subscription->ends_on->toDateString())) {
                $fail('Choose a future session within the active subscription dates.');
            }
        }]])->validate();
    }

    public function lockUsers(User ...$users): void
    {
        User::whereIn('id', array_map(fn ($user) => $user->id, $users))->orderBy('id')->lockForUpdate()->get();
    }

    public function transaction(User $coach, User $trainee, Closure $action, bool $manage = true): mixed
    {
        return DB::transaction(function () use ($coach, $trainee, $action, $manage) {
            $this->lockUsers($coach, $trainee);
            $relationship = $this->relationship($coach, $trainee, true);
            $subscription = $this->subscription($relationship, true);
            abort_if($manage && ! $subscription, 403, 'An active coaching subscription is required.');

            return $action($relationship, $subscription);
            // Callbacks can copy/upload files. Let their failure cleanup run instead of
            // replaying filesystem effects after a database deadlock.
        });
    }
}
