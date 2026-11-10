<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\UserProfile;
use App\Models\WebPushSubscription;
use App\Services\Notifications\PushDevices;
use App\Services\Notifications\PushTransport;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingsPageData
{
    public function location(Request $request, string $path): string
    {
        $section = $request->query('section');

        return in_array($section, ['profile', 'account', 'preferences', 'notifications', 'devices'], true) ? $path.'?section='.$section : $path;
    }

    public function response(Request $request, string $component, array $extra = [])
    {
        $devices = app(PushDevices::class);
        $push = app(PushTransport::class);
        $response = Inertia::render($component, [
            'profile' => $request->user()->profile,
            'coachProfile' => $request->user()->isCoach() ? $request->user()->coachProfile : null,
            'options' => UserProfile::options(),
            'preferences' => NotificationPreference::settings($request->user()->id),
            'deviceEnabled' => (bool) $devices->current($request),
            'deviceCount' => WebPushSubscription::where('user_id', $request->user()->id)->count(),
            'pushConfigured' => $push->configured(),
            'publicKey' => $push->configured() ? config('webpush.vapid.publicKey') : null,
            ...$extra,
        ])->toResponse($request);
        if (! $devices->hash($request)) {
            $response->withCookie(cookie(PushDevices::COOKIE, bin2hex(random_bytes(32)), 525600, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response->header('Cache-Control', 'private, no-store');
    }
}
