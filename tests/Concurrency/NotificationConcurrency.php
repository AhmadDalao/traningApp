<?php

use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\Notifications\PushDeliveries;
use App\Services\Notifications\PushTransport;
use App\Services\Notifications\WorkoutReminders;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'training_planner_test') {
    throw new RuntimeException('Isolated test database required');
}

class RacePushTransport extends PushTransport
{
    public string $log;

    public $duringSend = null;

    public function configured(): bool
    {
        return true;
    }

    public function send(WebPushSubscription $subscription, array $payload, int $ttl, string $topic): string
    {
        file_put_contents($this->log, $topic."\n", FILE_APPEND | LOCK_EX);
        if ($this->duringSend) {
            ($this->duringSend)();
        }
        usleep(250000);

        return 'sent';
    }
}
$transport = new RacePushTransport;
$app->instance(PushTransport::class, $transport);
if (($argv[1] ?? '') === 'worker') {
    $transport->log = $argv[3];
    $result = app(PushDeliveries::class)->deliver((int) $argv[2]);
    echo $result;
    exit;
}
$checks = 0;
function verifyPush(bool $ok, string $label): void
{
    global $checks;
    if (! $ok) {
        throw new RuntimeException($label);
    } $checks++;
    echo 'PASS '.$label.PHP_EOL;
}
$log = tempnam(sys_get_temp_dir(), 'tp-push-race-');
$transport->log = $log;
$user = User::create(['name' => 'QA notification race', 'email' => 'qa-push-race-'.bin2hex(random_bytes(8)).'@example.test', 'password' => bin2hex(random_bytes(20))]);
config(['database.connections.push_race' => config('database.connections.mysql')]);
$other = DB::connection('push_race');
try {
    NotificationPreference::create(['user_id' => $user->id, 'workout_reminders' => true]);
    $device = WebPushSubscription::create(['user_id' => $user->id, 'device_hash' => hash('sha256', 'device'.$user->id), 'endpoint_hash' => hash('sha256', 'endpoint'.$user->id), 'endpoint' => 'https://fcm.googleapis.com/test', 'public_key' => 'key', 'auth_token' => 'auth']);
    $make = function () use ($user) {
        $start = now()->addHour();
        $entry = $user->scheduleEntries()->create(['title' => 'QA', 'entry_type' => 'training', 'scheduled_date' => $start->toDateString(), 'scheduled_time' => $start->format('H:i:s'), 'status' => 'planned']);
        app(WorkoutReminders::class)->enqueue(50);

        return [$entry, NotificationDelivery::where('schedule_entry_id', $entry->id)->firstOrFail()];
    };
    [$entry, $delivery] = $make();
    $a = new Process([PHP_BINARY, __FILE__, 'worker', (string) $delivery->id, $log]);
    $b = new Process([PHP_BINARY, __FILE__, 'worker', (string) $delivery->id, $log]);
    $a->start();
    $b->start();
    $a->wait();
    $b->wait();
    verifyPush($a->isSuccessful() && $b->isSuccessful(), 'Two real scheduler processes finish successfully');
    verifyPush(count(file($log)) === 1, 'Atomic claim allows one provider attempt for the same delivery');
    verifyPush($delivery->fresh()->status === 'sent' && $delivery->fresh()->attempts === 1, 'One durable sent record and attempt');
    [$entry, $delivery] = $make();
    $other->table('schedule_entries')->where('id', $entry->id)->update(['status' => 'completed']);
    verifyPush(app(PushDeliveries::class)->deliver($delivery->id) === 'cancelled', 'Completion committed after selection prevents send');
    [$entry, $delivery] = $make();
    $other->table('schedule_entries')->where('id', $entry->id)->update(['scheduled_time' => now()->addHours(3)->format('H:i:s')]);
    verifyPush(app(PushDeliveries::class)->deliver($delivery->id) === 'cancelled', 'Reschedule committed after selection prevents old reminder');
    [$entry, $delivery] = $make();
    $transport->duringSend = function () use ($other, $entry) {
        $other->table('schedule_entries')->where('id', $entry->id)->update(['scheduled_date' => today()->addDay()->toDateString()]);
        verifyPush(DB::transactionLevel() === 0, 'No database transaction spans the provider request');
    };
    verifyPush(app(PushDeliveries::class)->deliver($delivery->id) === 'sent', 'In-flight accepted push records its original occurrence without replay');
    verifyPush($entry->fresh()->scheduled_date->isTomorrow(), 'Concurrent reschedule remains intact during provider response');
    [$entry, $delivery] = $make();
    $transport->duringSend = fn () => $other->table('web_push_subscriptions')->where('id', $device->id)->delete();
    app(PushDeliveries::class)->deliver($delivery->id);
    verifyPush(! NotificationDelivery::whereKey($delivery->id)->exists(), 'Subscription deleted in-flight stays deleted with no resurrected delivery');
    verifyPush(! WebPushSubscription::whereKey($device->id)->exists(), 'Device revocation survives provider response');
    echo $checks.' notification concurrency checks passed.'.PHP_EOL;
} finally {
    $user->delete();
    unlink($log);
}
