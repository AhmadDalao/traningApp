<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachSubscription extends Model
{
    protected $fillable = ['coach_trainee_relationship_id', 'coach_subscription_plan_id', 'package_name', 'starts_on', 'ends_on', 'status', 'notes', 'ended_at'];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'ended_at' => 'datetime'];
    }

    public function relationship()
    {
        return $this->belongsTo(CoachTraineeRelationship::class, 'coach_trainee_relationship_id');
    }

    public function package()
    {
        return $this->belongsTo(CoachSubscriptionPlan::class, 'coach_subscription_plan_id');
    }

    public function isEffective(): bool
    {
        return $this->status === 'active' && $this->starts_on->lte(today()) && (! $this->ends_on || $this->ends_on->gte(today()));
    }

    public function getEffectiveStatusAttribute(): string
    {
        if ($this->status !== 'active') {
            return $this->status;
        }
        if ($this->starts_on->gt(today())) {
            return 'upcoming';
        }

        return $this->ends_on && $this->ends_on->lt(today()) ? 'expired' : 'active';
    }
}
