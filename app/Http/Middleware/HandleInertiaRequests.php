<?php

namespace App\Http\Middleware;

use App\Models\CoachTraineeRelationship;
use App\Models\TrainingPlan;
use App\Services\CoachAccess;
use App\Services\CoachWorkspaceSummary;
use App\Services\TrainingContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request),
            'auth' => ['user' => fn () => $request->user() ? [...$request->user()->only('id', 'name', 'email', 'training_code', 'account_type'), 'is_coach' => $request->user()->isCoach()] : null],
            'trainingContext' => function () use ($request) {
                if (! $request->user()) {
                    return null;
                }
                $context = app(TrainingContext::class);
                $access = app(CoachAccess::class);
                $coaching = $context->coaching();
                $relationship = $coaching ? $access->relationship($context->actor(), $context->owner()) : null;
                $plan = $request->route('trainingPlan') ?? $request->route('trainingDay')?->trainingPlan ?? $request->route('programRun')?->trainingPlan;
                $manage = ! $coaching || ($plan ? ($plan->assignment?->status === 'active' && $access->subscriptionFor($plan->assignment)) : $access->subscription($relationship));

                return ['workspace' => $request->attributes->get('coach_workspace') ? 'coach' : 'personal', 'prefix' => $context->prefix(), 'templates' => $context->templates(), 'coaching' => $coaching, 'canManage' => (bool) $manage, 'summary' => $coaching ? app(CoachWorkspaceSummary::class)->one($context->actor(), $context->owner()) : null, 'trainee' => $coaching ? $context->owner()->only('id', 'name', 'training_code') : null];
            },
            'flash' => ['success' => fn () => $request->session()->get('success'), 'schedule_review' => fn () => $request->session()->get('schedule_review')],
            'settingsSummary' => fn () => $request->user()?->isCoach() && $request->is('coach/settings*') ? [
                'profile' => $request->user()->coachProfile?->only('display_name', 'specialization'),
                'trainees' => CoachTraineeRelationship::where('coach_user_id', $request->user()->id)->where('status', 'active')->count(),
                'workouts' => app(CoachAccess::class)->sessions($request->user())->availableOnSchedule()->where('entry_type', 'training')->whereDate('scheduled_date', today())->count(),
                'programs' => TrainingPlan::where('user_id', $request->user()->id)->where('workspace_type', 'coach_template')->count(),
            ] : null,
            'today' => now()->toDateString(),
            'timezone' => config('app.timezone'),
        ];
    }
}
