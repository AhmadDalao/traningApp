<?php

namespace App\Services\Notifications;

use App\Models\WebPushSubscription;
use Illuminate\Http\Request;

class PushDevices
{
    public const COOKIE = 'tp_push_device';

    public function hash(Request $request): ?string
    {
        $value = $request->cookie(self::COOKIE);

        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) ? hash('sha256', $value) : null;
    }

    public function current(Request $request): ?WebPushSubscription
    {
        $hash = $this->hash($request);

        return $hash ? WebPushSubscription::where('user_id', $request->user()->id)->where('device_hash', $hash)->first() : null;
    }

    public function forget(Request $request): void
    {
        if ($hash = $this->hash($request)) {
            // The device token is an encrypted, HttpOnly cookie issued by this app.
            WebPushSubscription::where('device_hash', $hash)->delete();
        }
    }
}
