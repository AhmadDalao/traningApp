<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const TRAINEE = 'trainee';

    public const COACH = 'coach';

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (! $user->training_code) {
                do {
                    $code = 'TP-';
                    for ($i = 0; $i < 8; $i++) {
                        $code .= '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)];
                    }
                } while (self::where('training_code', $code)->exists());
                $user->training_code = $code;
            }
        });
    }

    public function coachProfile()
    {
        return $this->hasOne(CoachProfile::class);
    }

    public function isCoach(): bool
    {
        return $this->account_type === self::COACH && $this->coachProfile()->where('active', true)->exists();
    }

    public function personalPlans()
    {
        return $this->trainingPlans()->whereIn('workspace_type', ['personal', 'coach_assigned']);
    }

    public function trainingPlans()
    {
        return $this->hasMany(TrainingPlan::class);
    }

    public function scheduleEntries()
    {
        return $this->hasMany(ScheduleEntry::class);
    }

    // Eager-loading limits apply per user, keeping trainee cards to two bounded queries.
    public function nextPlannedSessions()
    {
        return $this->scheduleEntries()->upcoming()->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('id')->limit(1);
    }

    public function latestCompletedWorkouts()
    {
        return $this->scheduleEntries()->where('entry_type', 'training')->where('status', 'completed')
            ->orderByDesc('scheduled_date')->orderByDesc('scheduled_time')->orderByDesc('id')->limit(1);
    }

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function bodyWeightEntries()
    {
        return $this->hasMany(BodyWeightEntry::class);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
