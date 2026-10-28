<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function login()
    {
        return Inertia::render('Auth/Login');
    }

    public function register()
    {
        return Inertia::render('Auth/Register', ['accountType' => request('type') === 'coach' ? 'coach' : 'trainee']);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users,email', 'password' => ['required', 'confirmed', Password::min(8), 'max:72']]);
        $coach = $request->validate(['account_type' => 'sometimes|in:trainee,coach', 'display_name' => 'required_if:account_type,coach|nullable|string|max:100', 'specialization' => 'nullable|string|max:150']);
        // The unique constraint is the final guard if two registrations generate the same code.
        for ($attempt = 0; ; $attempt++) {
            try {
                $user = DB::transaction(function () use ($data, $coach) {
                    $user = User::create($data);
                    if (($coach['account_type'] ?? 'trainee') === 'coach') {
                        $user->forceFill(['account_type' => User::COACH])->save();
                        $user->coachProfile()->create(['display_name' => $coach['display_name'], 'specialization' => $coach['specialization'] ?? null]);
                    }

                    return $user;
                });
                break;
            } catch (UniqueConstraintViolationException $error) {
                $codeCollision = str_contains($error->getMessage(), 'users_training_code_unique') || str_contains($error->getMessage(), 'users.training_code');
                if (! $codeCollision || $attempt >= 4) {
                    throw $error;
                }
            }
        }
        Auth::login($user);
        $request->session()->regenerate();

        if ($user->isCoach()) {
            $request->session()->put('workspace', 'coach');

            return redirect('/coach');
        }
        $request->session()->put('show_onboarding', true);

        return to_route('dashboard');
    }

    public function authenticate(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string', 'remember' => 'nullable|boolean']);
        $key = Str::lower($request->input('email')).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        if (! Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        if ($request->session()->has('url.intended')) {
            return redirect()->intended(route('dashboard'));
        }
        if ($request->user()->isCoach() && $request->session()->get('workspace', 'coach') === 'coach') {
            return redirect('/coach');
        }

        if (! $request->user()->profile || $request->user()->profile->needsOnboarding()) {
            $request->session()->put('show_onboarding', true);

            return to_route('dashboard');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login');
    }
}
