<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachTraineeRelationship extends Model
{
    protected $fillable = ['coach_user_id', 'trainee_user_id', 'status', 'requested_by', 'requested_at', 'approved_at', 'ended_at', 'ended_reason'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'approved_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_user_id');
    }

    public function trainee()
    {
        return $this->belongsTo(User::class, 'trainee_user_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(CoachSubscription::class, 'coach_trainee_relationship_id');
    }

    public function assignments()
    {
        return $this->hasMany(CoachPlanAssignment::class, 'coach_trainee_relationship_id');
    }
}
