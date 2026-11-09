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
            return app(CoachAccess::class)->plans($this->actor(), $this->owner(), $manage);
        }

        return $query->whereIn('workspace_type', $this->templates() ? ['coach_template'] : ['personal', 'coach_assigned']);
    }

    /** Read-only one-off choices: authored definitions grouped by block, never occurrences. */
    public function schedulingPlans()
    {
        return $this->plans(true)->with([
            'trainingDays' => fn ($q) => $q->withThumbnail(),
            'blocks',
            'programRuns' => fn ($q) => $q->select('id', 'training_plan_id', 'status', 'started_on')->currentFirst()->withMin(['sessions as next_week' => fn ($s) => $s->where('entry_type', 'training')->whereNotIn('status', ['completed', 'skipped'])], 'program_week'),
        ])->orderBy('name')->get()->map(function ($plan) {
            $week = $plan->programRuns->first()?->next_week;
            $block = $plan->blocks->first(fn ($b) => $week && $b->start_week <= $week && $b->end_week >= $week) ?? $plan->blocks->first();

            return [...$plan->only('id', 'name', 'workspace_type'),
                'training_days' => $plan->trainingDays,
                'workout_blocks' => $plan->blocks->map->only(['id', 'name', 'start_week', 'end_week']),
                'default_block_id' => $block?->id];
        });
    }

    /** Same server-selected training on Overview, Training and Progress. */
    public function currentTraining(): ?array
    {
        if (! $this->coaching()) {
            return null;
        }
        $current = app(CoachWorkspaceSummary::class)->one($this->actor(), $this->owner())['plan'];
        if (! $current) {
            return null;
        }
        $plan = $this->plans()->withThumbnail()->find($current['id']);
        $run = $current['run_id'] ? $plan?->programRuns()->find($current['run_id']) : null;
        $position = $run ? app(ProgramLifecycle::class)->position($run) : null;

        return $plan ? [...$current, 'thumbnail_url' => $plan->thumbnail_url,
            'block' => $position['current_block']['name'] ?? null,
            'progress_percent' => $position['progress_percent'] ?? null,
            'started_on' => $run?->started_on?->toDateString()] : null;
    }

    public function sessions()
    {
        return $this->coaching()
            ? app(CoachAccess::class)->sessions($this->actor(), $this->owner())
            : $this->owner()->scheduleEntries();
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
