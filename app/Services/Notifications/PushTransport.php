<?php

namespace App\Services\Notifications;

use App\Models\WebPushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Psr\Log\NullLogger;

class PushTransport
{
    public function configured(): bool
    {
        if (! config('webpush.enabled')) {
            return false;
        }
        try {
            $vapid = config('webpush.vapid');
            if (! $vapid['subject'] || ! $vapid['publicKey'] || ! $vapid['privateKey']) {
                return false;
            }
            VAPID::validate($vapid);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // Only provider-owned HTTPS endpoints; never use arbitrary user URLs as HTTP targets.
    public static function validEndpoint(string $endpoint): bool
    {
        $url = parse_url($endpoint);
        if (! $url || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || (($url['port'] ?? 443) !== 443)) {
            return false;
        }
        $host = strtolower($url['host'] ?? '');

        return $host === 'fcm.googleapis.com'
            || $host === 'updates.push.services.mozilla.com'
            || str_ends_with($host, '.push.services.mozilla.com')
            || str_ends_with($host, '.push.apple.com')
            || str_ends_with($host, '.notify.windows.com');
    }

    public static function validKey(string $value, int $bytes): bool
    {
        if (! preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $value)) {
            return false;
        }
        $raw = base64_decode(strtr($value, '-_', '+/'), true);

        return $raw !== false && strlen($raw) === $bytes && ($bytes !== 65 || ord($raw[0]) === 4);
    }

    public function send(WebPushSubscription $subscription, array $payload, int $ttl, string $topic): string
    {
        if (! $this->configured()) {
            return 'unconfigured';
        }
        try {
            if (! self::validEndpoint($subscription->endpoint)) {
                return 'invalid';
            }
            $push = new WebPush(['VAPID' => config('webpush.vapid')], [], config('webpush.timeout'), ['allow_redirects' => false, 'connect_timeout' => 3], new NullLogger);
            $report = $push->sendOneNotification(Subscription::create([
                'endpoint' => $subscription->endpoint, 'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token, 'contentEncoding' => 'aes128gcm',
            ]), json_encode($payload, JSON_THROW_ON_ERROR), ['TTL' => max(0, min(300, $ttl)), 'urgency' => 'normal', 'topic' => substr($topic, 0, 32)]);
            if ($report->isSuccess()) {
                return 'sent';
            }
            if ($report->isSubscriptionExpired()) {
                return 'expired';
            }
            $code = $report->getResponse()?->getStatusCode();

            return $code === null || $code === 429 || $code >= 500 ? 'temporary' : 'rejected';
        } catch (\Throwable) {
            // Provider exceptions can contain credentials/endpoints. Never log their text.
            return 'temporary';
        }
    }
}
