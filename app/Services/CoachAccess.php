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

    public function assignment(User $coach, User $trainee, TrainingPlan $plan, bool $manage = false, bool $lock = false): CoachPlanAssignment
    {
        $relationship = $this->relationship($coach, $trainee, $lock);
        abort_unless($plan->user_id === $trainee->id && $plan->workspace_type === 'coach_assigned', 403);
        $assignment = $relationship->assignments()->where('assigned_training_plan_id', $plan->id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        if ($manage) {
            abort_unless($assignment->status === 'active' && $this->subscription($relationship, $lock), 403, 'An active coaching subscription and assignment are required.');
        }

        return $assignment;
    }

    public function futureSession(User $coach, User $trainee, ScheduleEntry $entry): void
    {
        abort_unless($entry->user_id === $trainee->id && $entry->scheduled_by_user_id === $coach->id, 403);
        abort_unless($entry->status === 'planned' && ! $entry->started_at && $entry->workout_snapshot === null && ! $entry->sessionExercises()->exists(), 403, 'Saved and active workouts are protected.');
        abort_unless($this->future($entry->scheduled_date->toDateString(), $entry->scheduled_time), 403, 'Past sessions are protected.');
        $plan = $entry->trainingDay?->trainingPlan;
        abort_unless($plan, 403);
        $this->assignment($coach, $trainee, $plan, true);
        $this->scheduleDate($this->subscription($this->relationship($coach, $trainee)), $entry->scheduled_date->toDateString(), $entry->scheduled_time);
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
        return $date > today()->toDateString() || ($date === today()->toDateString() && $time !== null && substr($time, 0, 5) > now()->format('H:i'));
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
        }, 3);
    }
}
