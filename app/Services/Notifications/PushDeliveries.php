<?php

namespace App\Services\Notifications;

use App\Models\CoachTraineeRelationship;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\ScheduleEntry;
use App\Models\User;
use App\Models\WebPushSubscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PushDeliveries
{
    public function enqueue(int $userId, string $kind, string $eventKey, array $context, array $payload, CarbonInterface $expires, ?int $entryId = null, ?int $deviceId = null): int
    {
        $count = 0;
        foreach (WebPushSubscription::where('user_id', $userId)->when($deviceId, fn ($q) => $q->whereKey($deviceId))->limit(10)->get() as $device) {
            $key = hash('sha256', $eventKey.':'.$device->id);
            $row = DB::transaction(function () use ($device, $userId, $key, $entryId, $kind, $context, $payload, $expires) {
                if (! User::whereKey($userId)->lockForUpdate()->first(['id'])) {
                    return null;
                }
                if (! WebPushSubscription::whereKey($device->id)->where('user_id', $userId)->lockForUpdate()->first(['id'])) {
                    return null;
                }
                if ($entryId && ! ScheduleEntry::whereKey($entryId)->where('user_id', $userId)->lockForUpdate()->first(['id'])) {
                    return null;
                }

                return NotificationDelivery::firstOrCreate(['dedupe_key' => $key], [
                    'user_id' => $userId, 'web_push_subscription_id' => $device->id, 'schedule_entry_id' => $entryId,
                    'kind' => $kind, 'context' => $context, 'payload' => [...$payload, 'tag' => 'tp-'.$key],
                    'available_at' => now(), 'expires_at' => $expires,
                ]);
            }, 3);
            $count += $row?->wasRecentlyCreated ? 1 : 0;
        }

        return $count;
    }

    public function valid(NotificationDelivery $row): bool
    {
        if ($row->expires_at->lte(now()) || ! WebPushSubscription::where('user_id', $row->user_id)->whereKey($row->web_push_subscription_id)->exists()) {
            return false;
        }
        if ($row->kind === 'workout') {
            return app(WorkoutReminders::class)->valid($row->user_id, $row->schedule_entry_id, $row->context);
        }
        if ($row->kind === 'test') {
            return true;
        }
        if (! NotificationPreference::settings($row->user_id)['coach_updates']) {
            return false;
        }
        $relationship = CoachTraineeRelationship::find($row->context['relationship_id'] ?? null);

        return $relationship && in_array($row->user_id, [$relationship->coach_user_id, $relationship->trainee_user_id], true)
            && $relationship->status === ($row->kind === 'coach_request' ? 'pending' : 'active');
    }

    public function deliver(int $id): string
    {
        if (! app(PushTransport::class)->configured()) {
            return 'unconfigured';
        }
        $token = (string) Str::uuid();
        // Atomic compare-and-set; no transaction/row lock spans the provider request.
        $claimed = NotificationDelivery::whereKey($id)->where('attempts', '<', config('webpush.max_attempts'))
            ->where('available_at', '<=', now())->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($q) => $q->where('status', 'sending')->where('lease_until', '<', now())))
            ->update(['status' => 'sending', 'attempt_token' => $token, 'lease_until' => now()->addMinute(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if (! $claimed) {
            return 'unclaimed';
        }
        $row = NotificationDelivery::find($id);
        if (! $row || ! $this->valid($row)) {
            NotificationDelivery::whereKey($id)->where('attempt_token', $token)->update(['status' => 'cancelled', 'last_result' => 'ineligible', 'lease_until' => null]);

            return 'cancelled';
        }
        $device = WebPushSubscription::where('user_id', $row->user_id)->find($row->web_push_subscription_id);
        if (! $device) {
            return 'cancelled';
        }
        // Fresh authoritative state immediately before send. An already accepted remote push
        // cannot be recalled if a user changes their workout while the HTTP request is in flight.
        if (! $this->valid($row->fresh() ?? $row)) {
            NotificationDelivery::whereKey($id)->where('attempt_token', $token)->update(['status' => 'cancelled', 'last_result' => 'ineligible']);

            return 'cancelled';
        }
        $result = app(PushTransport::class)->send($device, $row->payload, (int) now()->diffInSeconds($row->expires_at, false), $row->dedupe_key);
        if (in_array($result, ['expired', 'invalid'], true)) {
            WebPushSubscription::whereKey($device->id)->delete();

            return $result;
        }
        $retry = in_array($result, ['temporary', 'unconfigured'], true) && $row->attempts < config('webpush.max_attempts');
        NotificationDelivery::whereKey($id)->where('attempt_token', $token)->update([
            'status' => $result === 'sent' ? 'sent' : ($retry ? 'pending' : 'failed'), 'last_result' => $result,
            'sent_at' => $result === 'sent' ? now() : null, 'lease_until' => null,
            'available_at' => now()->addMinutes($row->attempts),
        ]);

        return $result;
    }

    public function run(int $limit, float $deadline): array
    {
        $results = [];
        $rows = NotificationDelivery::whereIn('status', ['pending', 'sending'])->where('available_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))->orderBy('id')->limit($limit)->pluck('id');
        foreach ($rows as $id) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $result = $this->deliver($id);
            $results[$result] = ($results[$result] ?? 0) + 1;
        }

        return $results;
    }

    public function prune(): void
    {
        $expired = NotificationDelivery::whereIn('status', ['pending', 'sending'])
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))
            ->where(fn ($q) => $q->where('expires_at', '<=', now())->orWhere('attempts', '>=', config('webpush.max_attempts')))
            ->limit(500)->pluck('id');
        NotificationDelivery::whereIn('id', $expired)->update(['status' => 'failed', 'last_result' => 'exhausted']);
        // Keep dedupe beyond the reminder window / Coach event lifetime, then remove metadata.
        $ids = NotificationDelivery::where('expires_at', '<', now()->subDays(30))->limit(500)->pluck('id');
        NotificationDelivery::whereIn('id', $ids)->delete();
    }
}
