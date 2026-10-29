<?php

namespace App\Console\Commands;

use App\Services\Notifications\PushDeliveries;
use App\Services\Notifications\PushTransport;
use App\Services\Notifications\WorkoutReminders;
use Illuminate\Console\Command;

class SendWorkoutNotifications extends Command
{
    protected $signature = 'notifications:send';

    protected $description = 'Send a bounded batch of opted-in workout and Coach notifications';

    public function handle(WorkoutReminders $reminders, PushDeliveries $deliveries, PushTransport $push): int
    {
        if (! $push->configured()) {
            $this->info('Push disabled or unconfigured. Nothing sent.');

            return self::SUCCESS;
        }
        $deadline = microtime(true) + config('webpush.run_seconds');
        $limit = config('webpush.batch_size');
        $deliveries->prune();
        $queued = $reminders->enqueue($limit);
        $results = $deliveries->run($limit, $deadline);
        $this->info(json_encode(['queued' => $queued, 'results' => $results]));

        return self::SUCCESS;
    }
}
