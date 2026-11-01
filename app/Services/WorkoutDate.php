<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Calendar-local availability, shared by scalar checks and database scopes. */
class WorkoutDate
{
    public function elapsed(string $date, ?string $time): bool
    {
        $now = CarbonImmutable::now(config('app.timezone'));

        return $date < $now->toDateString() || ($date === $now->toDateString() && $time !== null
            && CarbonImmutable::parse($date.' '.$time, config('app.timezone'))->lte($now));
    }

    /** Resolved outcomes count even when recorded before their scheduled timestamp. */
    public function due(string $date, ?string $time, string $status): bool
    {
        return in_array($status, ['completed', 'skipped'], true) || $this->elapsed($date, $time);
    }

    public function whereDue(Builder $query): void
    {
        $query->where(function (Builder $due) {
            $this->whereElapsed($due);
            $due->orWhereIn('status', ['completed', 'skipped']);
        });
    }

    public function whereElapsed(Builder $query, bool $elapsed = true): void
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $query->where(function (Builder $dates) use ($now, $elapsed) {
            $dates->whereDate('scheduled_date', $elapsed ? '<' : '>', $now->toDateString())
                ->orWhere(function (Builder $today) use ($now, $elapsed) {
                    $today->whereDate('scheduled_date', $now->toDateString())->where(function (Builder $time) use ($now, $elapsed) {
                        if ($elapsed) {
                            $time->whereNotNull('scheduled_time')->where('scheduled_time', '<=', $now->format('H:i:s'));
                        } else {
                            $time->whereNull('scheduled_time')->orWhere('scheduled_time', '>', $now->format('H:i:s'));
                        }
                    });
                });
        });
    }
}
