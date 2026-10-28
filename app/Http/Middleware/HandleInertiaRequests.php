<?php

namespace App\Http\Middleware;

use App\Services\CoachAccess;
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
                $plan = $request->route('trainingPlan') ?? $request->route('trainingDay')?->trainingPlan;
                $manage = ! $coaching || ($access->subscription($relationship) && (! $plan || $plan->assignment?->status === 'active'));

                return ['workspace' => $request->attributes->get('coach_workspace') ? 'coach' : 'personal', 'prefix' => $context->prefix(), 'templates' => $context->templates(), 'coaching' => $coaching, 'canManage' => (bool) $manage, 'trainee' => $coaching ? $context->owner()->only('id', 'name', 'training_code') : null];
            },
            'flash' => ['success' => fn () => $request->session()->get('success'), 'schedule_review' => fn () => $request->session()->get('schedule_review')],
            'today' => now()->toDateString(),
            'timezone' => config('app.timezone'),
        ];
    }
}
