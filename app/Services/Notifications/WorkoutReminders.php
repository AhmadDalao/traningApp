<?php

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\ScheduleEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class WorkoutReminders
{
    public function eligible(): Builder
    {
        return ScheduleEntry::query()->availableOnSchedule()->where('entry_type', 'training')->where('status', 'planned')->whereNull('started_at')->whereNotNull('scheduled_time');
    }

    public function stamp(ScheduleEntry $entry): string
    {
        return $entry->scheduled_date->toDateString().' '.substr($entry->scheduled_time, 0, 8);
    }

    public function due(ScheduleEntry $entry, int $minutes): bool
    {
        $start = CarbonImmutable::parse($this->stamp($entry), config('app.timezone'));
        $due = $start->subMinutes($minutes);

        return $start->isFuture() && $due->lte(now()) && $due->gte(now()->subMinutes(config('webpush.lookback_minutes')));
    }

    public function enqueue(int $limit): int
    {
        // The global date/time index bounds this scan to today's/tomorrow's due windows.
        $query = $this->eligible()->join('notification_preferences as prefs', 'prefs.user_id', '=', 'schedule_entries.user_id')
            ->where('prefs.workout_reminders', true)->select('schedule_entries.*')->where(function ($windows) {
                foreach ([15, 30, 60, 120] as $minutes) {
                    $end = now()->addMinutes($minutes);
                    $start = $end->copy()->subMinutes(config('webpush.lookback_minutes'));
                    $windows->orWhere(function ($q) use ($minutes, $start, $end) {
                        $q->where('prefs.reminder_minutes', $minutes)->where('scheduled_date', '>=', $start->toDateString())->where('scheduled_date', '<', $end->copy()->addDay()->toDateString())
                            ->where(fn ($q) => $q->whereDate('scheduled_date', '>', $start->toDateString())->orWhere('scheduled_time', '>=', $start->format('H:i:s')))
                            ->where(fn ($q) => $q->whereDate('scheduled_date', '<', $end->toDateString())->orWhere('scheduled_time', '<=', $end->format('H:i:s')));
                    });
                }
            })->whereExists(fn ($q) => $q->selectRaw('1')->from('web_push_subscriptions')->whereColumn('web_push_subscriptions.user_id', 'schedule_entries.user_id'));
        $count = 0;
        // Chunking avoids starvation behind already-sent rows; scan is bounded as well as sending.
        foreach ($query->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('schedule_entries.id')->limit($limit * 10)->get() as $entry) {
            $minutes = NotificationPreference::settings($entry->user_id)['reminder_minutes'];
            if (! $this->due($entry, $minutes)) {
                continue;
            }
            $stamp = $this->stamp($entry);
            $count += app(PushDeliveries::class)->enqueue($entry->user_id, 'workout', "workout:{$entry->id}:{$stamp}:{$minutes}",
                ['stamp' => $stamp, 'minutes' => $minutes],
                ['title' => 'Workout reminder', 'body' => 'You have a workout coming up. Open your Calendar to review it.', 'url' => '/notifications/open/session/'.$entry->id],
                CarbonImmutable::parse($stamp), $entry->id);
            if ($count >= $limit) {
                break;
            }
        }

        return $count;
    }

    public function valid(int $userId, int $entryId, array $context): bool
    {
        $prefs = NotificationPreference::settings($userId);
        $entry = $this->eligible()->where('user_id', $userId)->find($entryId);

        return $prefs['workout_reminders'] && $prefs['reminder_minutes'] === $context['minutes'] && $entry
            && $this->stamp($entry) === $context['stamp'] && $this->due($entry, $context['minutes']);
    }
}
