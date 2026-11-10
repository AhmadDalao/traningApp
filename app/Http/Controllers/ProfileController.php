<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveProfileRequest;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\BodyWeightHistory;
use App\Services\ProfileSettings;
use App\Services\SettingsPageData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return app(SettingsPageData::class)->response($request, 'Profile/Show');
    }

    public function edit(Request $request)
    {
        return app(SettingsPageData::class)->response($request, 'Profile/Edit');
    }

    public function preferences(Request $request)
    {
        return app(SettingsPageData::class)->response($request, 'Onboarding/Index', ['editing' => true]);
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

        return redirect(app(SettingsPageData::class)->location($request, $request->is('coach/settings/account') ? '/coach/settings' : route('profile.show')))->with('success', 'Profile updated.');
    }

    public function complete(SaveProfileRequest $request, BodyWeightHistory $history)
    {
        $this->save($request, $history, true);
        $request->session()->forget('show_onboarding');

        return to_route('recommendations.index')->with('success', 'Your preferences are saved.');
    }

    private function save(SaveProfileRequest $request, BodyWeightHistory $history, bool $complete): void
    {
        DB::transaction(function () use ($request, $complete) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            $data = $request->validated();
            $notifications = $data['notifications'] ?? null;
            unset($data['notifications']);
            app(ProfileSettings::class)->save($user, $data, $complete);
            if ($notifications !== null) {
                NotificationPreference::updateOrCreate(['user_id' => $user->id], $notifications);
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
