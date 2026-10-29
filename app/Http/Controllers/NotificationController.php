<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\Notifications\PushDeliveries;
use App\Services\Notifications\PushDevices;
use App\Services\Notifications\PushTransport;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class NotificationController extends Controller
{
    public function index(Request $request, PushDevices $devices, PushTransport $push)
    {
        $request->attributes->set('coach_workspace', $request->session()->get('workspace', 'coach') === 'coach' && $request->user()->isCoach());
        $response = Inertia::render('Notifications/Index', [
            'preferences' => NotificationPreference::settings($request->user()->id),
            'deviceEnabled' => (bool) $devices->current($request),
            'deviceCount' => WebPushSubscription::where('user_id', $request->user()->id)->count(),
            'pushConfigured' => $push->configured(),
            'publicKey' => $push->configured() ? config('webpush.vapid.publicKey') : null,
        ])->toResponse($request);
        if (! $devices->hash($request)) {
            $response->withCookie(cookie(PushDevices::COOKIE, bin2hex(random_bytes(32)), 525600, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response->header('Cache-Control', 'private, no-store');
    }

    public function preferences(Request $request)
    {
        $data = $request->validate(['workout_reminders' => 'required|boolean', 'coach_updates' => 'required|boolean', 'reminder_minutes' => 'required|integer|in:15,30,60,120']);
        NotificationPreference::updateOrCreate(['user_id' => $request->user()->id], $data);

        return response()->json(['saved' => true]);
    }

    public function subscribe(Request $request, PushDevices $devices, PushTransport $push)
    {
        abort_unless($push->configured(), 503, 'Push notifications are not configured yet.');
        $hash = $devices->hash($request);
        abort_unless($hash, 409, 'Open Notification settings before enabling this device.');
        $data = $request->validate(['endpoint' => 'required|string|max:2048', 'keys' => 'required|array:p256dh,auth', 'keys.p256dh' => 'required|string|max:100', 'keys.auth' => 'required|string|max:30']);
        abort_unless(PushTransport::validEndpoint($data['endpoint']) && PushTransport::validKey($data['keys']['p256dh'], 65) && PushTransport::validKey($data['keys']['auth'], 16), 422, 'This push subscription is invalid or its provider is unsupported.');
        try {
            DB::transaction(function () use ($request, $data, $hash) {
                User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $endpointHash = hash('sha256', $data['endpoint']);
                $existing = WebPushSubscription::where('endpoint_hash', $endpointHash)->first();
                abort_if($existing && ($existing->user_id !== $request->user()->id || $existing->device_hash !== $hash), 409, 'This browser subscription belongs to another device association. Reset it first.');
                $device = WebPushSubscription::where('device_hash', $hash)->first();
                abort_if($device && $device->user_id !== $request->user()->id, 409);
                abort_if(! $device && WebPushSubscription::where('user_id', $request->user()->id)->count() >= 10, 422, 'You can enable up to 10 devices. Disable an older device first.');
                if ($device && $device->endpoint_hash !== $endpointHash) {
                    $device->delete();
                    $device = null;
                }
                ($device ?? new WebPushSubscription)->fill(['user_id' => $request->user()->id, 'device_hash' => $hash, 'endpoint_hash' => $endpointHash,
                    'endpoint' => $data['endpoint'], 'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth']])->save();
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This subscription is already associated. Refresh settings and try again.');
        }

        return response()->json(['enabled' => true]);
    }

    public function unsubscribe(Request $request, PushDevices $devices)
    {
        $devices->current($request)?->delete();

        return response()->json(['enabled' => false]);
    }

    public function test(Request $request, PushDevices $devices, PushDeliveries $deliveries, PushTransport $push)
    {
        abort_unless($push->configured(), 503, 'Push notifications are not configured yet.');
        $device = $devices->current($request);
        abort_unless($device, 422, 'Enable this device first.');
        $deliveries->enqueue($request->user()->id, 'test', 'test:'.$device->id.':'.now()->format('Y-m-d-H-i'), [],
            ['title' => 'Training Planner', 'body' => 'Your test notification is working.', 'url' => '/notifications'], now()->addMinutes(5), deviceId: $device->id);

        return response()->json(['message' => 'Test queued. It should arrive when the scheduler next runs.']);
    }

    public function open(Request $request, string $kind, int $id)
    {
        $row = $kind === 'session' ? ScheduleEntry::where('user_id', $request->user()->id)->find($id) : TrainingPlan::where('user_id', $request->user()->id)->find($id);
        if (! $row) {
            return redirect($kind === 'session' ? '/calendar' : '/training-plans')->with('success', 'This item is no longer available. Your current training is shown here.');
        }

        return redirect($kind === 'session' ? '/schedule-entries/'.$row->id : '/training-plans/'.$row->id);
    }
}
