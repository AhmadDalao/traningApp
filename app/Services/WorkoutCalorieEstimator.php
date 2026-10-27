<?php

namespace App\Services;

use App\Models\ScheduleEntry;

class WorkoutCalorieEstimator
{
    // Approximate session-level MET assumptions, not measured energy expenditure.
    private const MET = [
        'strength' => ['light' => 3.0, 'moderate' => 3.5, 'vigorous' => 6.0],
        'cardio' => ['light' => 4.0, 'moderate' => 6.0, 'vigorous' => 8.0],
        'mobility' => ['light' => 2.0, 'moderate' => 2.5, 'vigorous' => 3.0],
    ];

    public function estimate(?float $bodyWeightKg, ?int $durationMinutes, string $workoutCategory, string $intensity = 'moderate'): ?int
    {
        $met = self::MET[$workoutCategory][$intensity] ?? null;
        if (! $met || ! is_finite($bodyWeightKg ?? 0) || $bodyWeightKg < 20 || $bodyWeightKg > 500 || ! $durationMinutes || $durationMinutes < 1 || $durationMinutes > 1440) {
            return null;
        }

        return (int) round($met * 3.5 * $bodyWeightKg / 200 * $durationMinutes);
    }

    public function forSession(ScheduleEntry $entry): ?int
    {
        if ($entry->entry_type !== 'training' || $entry->status !== 'completed' || ! $entry->duration_minutes) {
            return null;
        }
        $weight = $entry->user->bodyWeightEntries()->where('recorded_date', '<=', $entry->scheduled_date->toDateString())
            ->orderByDesc('recorded_date')->orderByDesc('id')->value('weight_kg')
            ?? $entry->user->profile()->value('current_weight_kg');
        $category = match ($entry->focus_area) {
            'Cardio' => 'cardio',
            'Mobility' => 'mobility',
            default => 'strength',
        };

        return $this->estimate($weight === null ? null : (float) $weight, $entry->duration_minutes, $category, $entry->intensity ?? 'moderate');
    }
}
