<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveProfileRequest;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\BodyWeightHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return Inertia::render('Profile/Show', $this->props($request));
    }

    public function edit(Request $request)
    {
        return Inertia::render('Profile/Edit', $this->props($request));
    }

    public function preferences(Request $request)
    {
        return Inertia::render('Onboarding/Index', [...$this->props($request), 'editing' => true]);
    }

    public function updatePreferences(SaveProfileRequest $request, BodyWeightHistory $history)
    {
        $this->save($request, $history, false);

        return to_route('recommendations.index')->with('success', 'Preferences updated. Explore your new recommendations.');
    }

    public function onboarding(Request $request)
    {
        $request->session()->forget('show_onboarding');

        return Inertia::render('Onboarding/Index', $this->props($request));
    }

    public function props(Request $request): array
    {
        return ['profile' => $request->user()->profile()->first(), 'options' => UserProfile::options()];
    }

    public function update(SaveProfileRequest $request, BodyWeightHistory $history)
    {
        $this->save($request, $history, false);

        return to_route('profile.show')->with('success', 'Profile updated.');
    }

    public function complete(SaveProfileRequest $request, BodyWeightHistory $history)
    {
        $this->save($request, $history, true);
        $request->session()->forget('show_onboarding');

        return to_route('recommendations.index')->with('success', 'Your preferences are saved.');
    }

    private function save(SaveProfileRequest $request, BodyWeightHistory $history, bool $complete): void
    {
        DB::transaction(function () use ($request, $history, $complete) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            $data = $request->validated();
            if (array_key_exists('training_goals', $data)) {
                $data['training_goals'] ??= [];
                $data['training_goal'] = $data['training_goals'][0] ?? null;
            } elseif (array_key_exists('training_goal', $data)) {
                $data['training_goals'] = $data['training_goal'] ? [$data['training_goal']] : [];
            }
            if (isset($data['name'])) {
                $user->update(['name' => $data['name']]);
            }
            $weight = $data['current_weight_kg'] ?? null;
            unset($data['name'], $data['current_weight_kg']);
            $profile = $user->profile()->firstOrCreate();
            if (! in_array(array_key_exists('body_weight_goal', $data) ? $data['body_weight_goal'] : $profile->body_weight_goal, ['lose', 'gain'], true)) {
                $data['target_body_weight_kg'] = null;
            }
            if ($complete) {
                $data = [...$data, 'onboarding_completed_at' => now(), 'onboarding_skipped_at' => null];
            }
            $profile->update($data);
            if ($weight !== null && ($profile->current_weight_kg === null || (float) $weight !== (float) $profile->current_weight_kg || ! $user->bodyWeightEntries()->exists())) {
                $user->bodyWeightEntries()->create(['weight_kg' => $weight, 'recorded_date' => today()->toDateString()]);
                $history->sync($user);
            }
        });
    }

    public function skip(Request $request)
    {
        $request->user()->profile()->updateOrCreate([], ['onboarding_skipped_at' => now()]);
        $request->session()->forget('show_onboarding');

        return to_route('dashboard')->with('success', 'You can complete your profile whenever you’re ready.');
    }
}
