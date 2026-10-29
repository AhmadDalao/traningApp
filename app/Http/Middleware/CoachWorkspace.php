<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\CoachAccess;
use Closure;
use Illuminate\Http\Request;

class CoachWorkspace
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->isCoach(), 403, 'This page is available to active coach accounts only.');
        $request->attributes->set('coach_workspace', true);
        if ($id = $request->route('trainee')) {
            $trainee = $id instanceof User ? $id : User::findOrFail($id);
            $relationship = app(CoachAccess::class)->relationship($request->user(), $trainee);
            $request->attributes->set('training_owner', $trainee);
            $request->attributes->set('coach_relationship', $relationship);
            // Existing controllers receive their original resource parameters, never a substituted auth user.
            $request->route()->forgetParameter('trainee');
        }

        return $next($request);
    }
}
