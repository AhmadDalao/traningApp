<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['context' => 'array', 'payload' => 'array', 'available_at' => 'datetime', 'expires_at' => 'datetime', 'lease_until' => 'datetime', 'sent_at' => 'datetime'];
    }
}
