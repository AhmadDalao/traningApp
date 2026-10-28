<?php

namespace App\Services;

use App\Models\TrainingDay;
use App\Models\TrainingPlan;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Explicit route context. Authentication always remains the actor. */
class TrainingContext
{
    public function actor(): User
    {
        return request()->user();
    }

    public function owner(): User
    {
        return request()->attributes->get('training_owner') ?? $this->actor();
    }

    public function coaching(): bool
    {
        return request()->attributes->has('training_owner');
    }

    public function templates(): bool
    {
        return request()->attributes->get('coach_workspace', false) && ! $this->coaching();
    }

    public function prefix(): string
    {
        return $this->coaching() ? '/coach/trainees/'.$this->owner()->id : ($this->templates() ? '/coach' : '');
    }

    public function url(string $path): string
    {
        return $this->prefix().$path;
    }

    public function plans(bool $manage = false)
    {
        $query = $this->owner()->trainingPlans();
        if ($this->coaching()) {
            $relationship = app(CoachAccess::class)->relationship($this->actor(), $this->owner());

            return $query->where('workspace_type', 'coach_assigned')->whereHas('assignment', fn ($q) => $q->where('coach_trainee_relationship_id', $relationship->id)->when($manage, fn ($q) => $q->where('status', 'active')));
        }

        return $query->whereIn('workspace_type', $this->templates() ? ['coach_template'] : ['personal', 'coach_assigned']);
    }

    public function plan(TrainingPlan $plan, string $ability = 'view'): void
    {
        if ($this->coaching()) {
            abort_if($ability === 'delete', 403, 'End the assignment instead. The trainee owns this plan.');
            app(CoachAccess::class)->assignment($this->actor(), $this->owner(), $plan, $ability !== 'view');
        } else {
            Gate::authorize($ability, $plan);
            abort_unless($this->plans()->whereKey($plan->id)->exists(), 404);
        }
    }

    public function day(TrainingDay $day, string $ability = 'view'): void
    {
        $this->plan($day->trainingPlan, $ability === 'delete' ? 'update' : $ability);
    }

    public function write(Closure $action): mixed
    {
        return $this->coaching() ? app(CoachAccess::class)->transaction($this->actor(), $this->owner(), $action) : DB::transaction(fn () => $action());
    }

    public function redirect(string $path)
    {
        return redirect($this->url($path));
    }
}
