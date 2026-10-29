<?php

namespace App\Services;

use App\Models\UserProfile;

class PlanRecommendations
{
    public function catalog(): array
    {
        $library = collect(json_decode(file_get_contents(resource_path('data/exercise-library.json')), true, flags: JSON_THROW_ON_ERROR))->keyBy('key');
        $plans = require resource_path('data/training-plans.php');

        return array_values(array_map(function ($plan) use ($library) {
            $plan['days_per_week'] = count($plan['days']);
            $plan['level_label'] = UserProfile::LEVELS[$plan['level']];
            $plan['equipment_label'] = UserProfile::EQUIPMENT[$plan['equipment']];
            $plan['focus_label'] = UserProfile::GOALS[$plan['primary_focus']];
            $plan['goal_labels'] = array_map(fn ($goal) => UserProfile::GOALS[$goal], $plan['goals']);
            $plan['split'] = array_column($plan['days'], 'title');
            foreach ($plan['days'] as &$day) {
                foreach ($day['exercises'] as &$prescription) {
                    $exercise = $library->get($prescription['key']);
                    if (! $exercise) {
                        throw new \LogicException('Catalog exercise is missing: '.$prescription['key']);
                    }
                    $prescription = [...$exercise, ...$prescription];
                }
            }

            return $plan;
        }, array_filter($plans, fn ($plan) => $plan['enabled'])));
    }

    public function forProfile(?UserProfile $profile, ?array $catalog = null): array
    {
        $ranked = array_map(fn ($plan) => [...$plan, ...$this->match($plan, $profile)], $catalog ?? $this->catalog());
        // Original catalog order breaks ties, keeping results deterministic.
        usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($ranked, 0, 3);
    }

    public function match(array $plan, ?UserProfile $profile): array
    {
        $score = 0;
        $reasons = [];
        $goals = $profile?->training_goals ?? [];
        foreach (array_slice($goals, 0, 3) as $index => $goal) {
            if (in_array($goal, $plan['goals'], true)) {
                $score += [140, 70, 35][$index];
                if ($goal === $plan['primary_focus']) {
                    $score += [50, 25, 12][$index];
                }
                $reasons[] = ['Primary', 'Secondary', 'Supporting'][$index].' goal: '.UserProfile::GOALS[$goal];
            }
        }
        if (! $goals && in_array('general_fitness', $plan['goals'], true)) {
            $score += 20;
        }

        $levels = ['beginner' => 0, 'intermediate' => 1, 'advanced' => 2];
        if ($profile?->experience_level) {
            $difference = $levels[$plan['level']] - $levels[$profile->experience_level];
            $score += $difference === 0 ? 45 : -abs($difference) * ($difference > 0 ? 60 : 20);
            if ($difference === 0) {
                $reasons[] = 'Matches your '.strtolower($plan['level_label']).' experience';
            }
        } elseif ($plan['level'] === 'beginner') {
            $score += 15;
            $reasons[] = 'A beginner-friendly starting point';
        }

        if ($profile?->training_days_per_week) {
            $difference = $plan['days_per_week'] - $profile->training_days_per_week;
            $score += $difference === 0 ? 50 : -abs($difference) * 22 - ($difference > 0 ? 15 : 0);
            if ($difference <= 0) {
                $reasons[] = $plan['days_per_week'].' training days fit your '.$profile->training_days_per_week.' available days';
            }
        } elseif ($plan['days_per_week'] === 3) {
            $score += 5;
        }

        $equipment = $profile?->equipment_access ?? [];
        if ($equipment) {
            $matches = $plan['equipment'] === 'bodyweight' || in_array('full_gym', $equipment, true) || in_array($plan['equipment'], $equipment, true);
            $score += $matches ? 65 : -130;
            if ($matches) {
                $reasons[] = $plan['equipment'] === 'bodyweight' ? 'No gym equipment required' : 'Works with your '.strtolower($plan['equipment_label']).' access';
            }
        } elseif ($plan['equipment'] === 'bodyweight') {
            $score += 10;
            $reasons[] = 'No gym equipment required';
        }

        if ($profile?->session_duration_minutes) {
            $difference = $plan['duration'] - $profile->session_duration_minutes;
            $score += $difference === 0 ? 15 : -abs($difference);
            if ($difference <= 0) {
                $reasons[] = 'About '.$plan['duration'].' minutes per session fits your available time';
            }
        }

        return ['score' => $score, 'reasons' => $reasons ?: ['An alternative to explore; check the schedule and equipment before choosing.']];
    }
}
