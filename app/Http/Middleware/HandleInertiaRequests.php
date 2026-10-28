<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request),
            'auth' => ['user' => fn () => $request->user()?->only('id', 'name', 'email')],
            'flash' => ['success' => fn () => $request->session()->get('success'), 'schedule_review' => fn () => $request->session()->get('schedule_review')],
            'today' => now()->toDateString(),
            'timezone' => config('app.timezone'),
        ];
    }
}
