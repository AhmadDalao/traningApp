<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    public const GOALS = ['build_muscle' => 'Build muscle', 'lose_fat' => 'Lose fat', 'get_stronger' => 'Get stronger', 'general_fitness' => 'General fitness', 'endurance' => 'Improve endurance', 'mobility' => 'Mobility / flexibility'];

    public const BODY_GOALS = ['maintain' => 'Maintain weight', 'lose' => 'Lose weight', 'gain' => 'Gain weight', 'not_tracking' => 'Not tracking'];

    public const LEVELS = ['beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced'];

    public const EQUIPMENT = ['full_gym' => 'Full gym', 'home_gym' => 'Home gym', 'dumbbells' => 'Dumbbells', 'bodyweight' => 'Bodyweight'];

    protected $fillable = ['date_of_birth', 'height_cm', 'current_weight_kg', 'training_goal', 'body_weight_goal', 'target_body_weight_kg', 'experience_level', 'training_days_per_week', 'session_duration_minutes', 'equipment_access', 'onboarding_completed_at', 'onboarding_skipped_at', 'weight_check_in_dismissed_until'];

    protected $appends = ['age', 'bmi'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d', 'height_cm' => 'decimal:2', 'current_weight_kg' => 'decimal:2', 'target_body_weight_kg' => 'decimal:2', 'equipment_access' => 'array', 'onboarding_completed_at' => 'datetime', 'onboarding_skipped_at' => 'datetime', 'weight_check_in_dismissed_until' => 'date:Y-m-d'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getAgeAttribute(): ?int
    {
        return $this->date_of_birth ? (int) $this->date_of_birth->diffInYears(today()) : null;
    }

    public function getBmiAttribute(): ?float
    {
        return $this->height_cm > 0 && $this->current_weight_kg > 0 ? round($this->current_weight_kg / (($this->height_cm / 100) ** 2), 1) : null;
    }

    public function needsOnboarding(): bool
    {
        return ! $this->onboarding_completed_at && ! $this->onboarding_skipped_at;
    }

    public static function options(): array
    {
        return ['goals' => self::GOALS, 'bodyGoals' => self::BODY_GOALS, 'levels' => self::LEVELS, 'equipment' => self::EQUIPMENT];
    }
}
