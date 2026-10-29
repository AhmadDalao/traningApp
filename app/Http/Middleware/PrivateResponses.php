<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PrivateResponses
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if ($request->user() || $request->header('X-Inertia') || $request->is('login', 'register')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
