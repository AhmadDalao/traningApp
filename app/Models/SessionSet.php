<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionSet extends Model
{
    protected $fillable = ['set_number', 'planned_reps_text', 'planned_weight_kg', 'actual_reps', 'actual_duration_seconds', 'actual_weight_kg', 'completed_at'];

    protected function casts(): array
    {
        return ['planned_weight_kg' => 'decimal:2', 'actual_weight_kg' => 'decimal:2', 'completed_at' => 'datetime'];
    }

    public function sessionExercise()
    {
        return $this->belongsTo(SessionExercise::class);
    }
}
