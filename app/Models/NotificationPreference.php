<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['workout_reminders' => 'boolean', 'coach_updates' => 'boolean', 'reminder_minutes' => 'integer'];
    }

    public static function settings(int $userId): array
    {
        return self::find($userId)?->only('workout_reminders', 'reminder_minutes', 'coach_updates')
            ?? ['workout_reminders' => false, 'reminder_minutes' => 60, 'coach_updates' => false];
    }
}
