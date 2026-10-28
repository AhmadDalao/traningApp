<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachSubscriptionPlan extends Model
{
    protected $fillable = ['coach_user_id', 'name', 'description', 'duration_days', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'duration_days' => 'integer'];
    }

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_user_id');
    }
}
