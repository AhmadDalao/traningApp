<?php

namespace App\Services;

use App\Models\UserProfile;

class PlanRecommendations
{
    private const PRESETS = [
        'full-body' => ['name' => 'Full Body', 'goals' => ['build_muscle', 'get_stronger', 'general_fitness'], 'days' => 3, 'focus' => ['Full Body'], 'description' => 'A simple whole-body routine with time between workouts.'],
        'upper-lower' => ['name' => 'Upper / Lower', 'goals' => ['build_muscle', 'get_stronger'], 'days' => 4, 'focus' => ['Chest', 'Legs'], 'description' => 'Alternate upper and lower body training across four days.'],
        'push-pull-legs' => ['name' => 'Push / Pull / Legs', 'goals' => ['build_muscle'], 'days' => 5, 'focus' => ['Chest', 'Back', 'Legs', 'Chest', 'Back'], 'description' => 'Five days of focused push, pull, and leg training.'],
        'mixed-fitness' => ['name' => 'Mixed Fitness', 'goals' => ['general_fitness', 'lose_fat'], 'days' => 3, 'focus' => ['Full Body', 'Cardio', 'Core'], 'description' => 'Combine strength, easy conditioning, and core work.'],
        'endurance' => ['name' => 'Cardio & Conditioning', 'goals' => ['endurance', 'lose_fat'], 'days' => 4, 'focus' => ['Cardio', 'Core'], 'description' => 'Build consistency with cardio and supporting core work.'],
        'mobility' => ['name' => 'Mobility & Flexibility', 'goals' => ['mobility'], 'days' => 3, 'focus' => ['Mobility'], 'description' => 'A short movement and flexibility routine.'],
    ];

    public function forProfile(?UserProfile $profile): array
    {
        $goals = $profile?->training_goals ?? [];
        $primary = $goals[0] ?? null;
        $secondary = $goals[1] ?? null;
        if (! $primary || ! $profile->experience_level || ! $profile->training_days_per_week || ! $profile->equipment_access || ! $profile->session_duration_minutes) {
            return [];
        }
        $gym = in_array('full_gym', $profile->equipment_access, true);
        $dumbbells = in_array('dumbbells', $profile->equipment_access, true);
        $equipment = $gym ? 'Full gym' : ($dumbbells ? 'Dumbbells' : 'Bodyweight');
        $catalog = collect(json_decode(file_get_contents(resource_path('data/exercise-library.json')), true, flags: JSON_THROW_ON_ERROR))->keyBy('key');
        $recommendations = [];
        $strengthFirst = in_array($primary, ['build_muscle', 'get_stronger'], true);
        $conditioningSecond = in_array($secondary, ['lose_fat', 'endurance'], true);
        foreach (self::PRESETS as $key => $preset) {
            $split = in_array($key, ['upper-lower', 'push-pull-legs'], true);
            if ($split && ($profile->experience_level === 'beginner' || $profile->training_days_per_week < $preset['days'] || (! $gym && ! $dumbbells))) {
                continue;
            }
            $bodyweightCombination = $key === 'full-body' && ! $gym && ! $dumbbells && $strengthFirst && $conditioningSecond && $profile->training_days_per_week >= 4;
            $focuses = $preset['focus'];
            if ($bodyweightCombination) {
                $preset['name'] = 'Bodyweight Strength & Conditioning';
                $preset['days'] = 4;
                $preset['description'] = 'Three resistance workouts and one conditioning workout, using bodyweight exercises.';
                $focuses = ['Chest', 'Legs', 'Full Body', 'Cardio'];
            } elseif ($secondary && $key === 'mobility' && $secondary !== 'mobility') {
                $focuses = ['Mobility', 'Full Body', 'Mobility'];
            } elseif ($secondary && $key === 'endurance' && $secondary !== 'mobility') {
                $focuses = ['Cardio', 'Full Body', 'Cardio', 'Core'];
            } elseif ($secondary && $key === 'mixed-fitness' && in_array($secondary, ['build_muscle', 'get_stronger'], true)) {
                $focuses = ['Full Body', 'Cardio', 'Full Body'];
            }
            $dayCount = min($profile->training_days_per_week, $preset['days']);
            $goalMatch = in_array($primary, $preset['goals'], true);
            $score = ($goalMatch ? 100 : 0) + (in_array($secondary, $preset['goals'], true) ? 20 : 0) + $dayCount * 3;
            if ($key === 'full-body' && $profile->experience_level === 'beginner') {
                $score += 20;
            }
            if ($key === 'upper-lower' && $profile->training_days_per_week === 4) {
                $score += 20;
            }
            if ($key === 'push-pull-legs' && $profile->training_days_per_week >= 5) {
                $score += 20;
            }
            $days = [];
            for ($i = 0; $i < $dayCount; $i++) {
                $focus = $focuses[$i % count($focuses)];
                $routine = $key === 'push-pull-legs' ? match ($focus) {
                    'Chest' => 'Push', 'Back' => 'Pull', default => $focus,
                } : $focus;
                $keys = $this->exerciseKeys($routine, $gym, $dumbbells);
                $limit = $profile->session_duration_minutes === 30 ? 3 : ($profile->session_duration_minutes === 90 ? 5 : 4);
                $keys = array_slice($keys, 0, $limit);
                $finisher = ($strengthFirst || $primary === 'general_fitness') && $conditioningSecond && ! $bodyweightCombination && $i === $dayCount - 1 && ! in_array($focus, ['Cardio', 'Mobility'], true);
                if ($finisher) {
                    $keys[count($keys) - 1] = $gym ? 'Running_Treadmill' : 'Trail_Running_Walking';
                } elseif ($secondary === 'mobility' && ! in_array($focus, ['Cardio', 'Mobility'], true)) {
                    $keys[count($keys) - 1] = 'Hamstring_Stretch';
                }
                $exercises = [];
                foreach ($keys as $index => $id) {
                    $exercise = $catalog->get('builtin:'.$id);
                    if (! $exercise) {
                        throw new \LogicException('Preset exercise is missing: '.$id);
                    }
                    $timed = in_array($exercise['category'], ['cardio', 'stretching'], true) || $id === 'Plank';
                    $reps = $primary === 'get_stronger' ? '5–8' : ($secondary === 'get_stronger' && $index === 0 ? '6–10' : '8–12');
                    if ($timed) {
                        $reps = '20–30 seconds';
                    }
                    if ($exercise['category'] === 'cardio') {
                        $minutes = $finisher
                            ? match ($profile->session_duration_minutes) {
                                30 => 5, 45 => 8, default => 10
                            }
                        : ($profile->session_duration_minutes <= 45 ? 10 : 20);
                        $reps = $minutes.' minutes';
                    }
                    $exercises[] = [...$exercise,
                        'sets' => $timed ? 1 : (($profile->experience_level === 'beginner' || $profile->session_duration_minutes === 30) ? 2 : ($profile->experience_level === 'advanced' ? 4 : 3)),
                        'reps' => $reps,
                    ];
                }
                $days[] = ['title' => ($routine === 'Chest' ? 'Upper Body' : $routine).' · '.chr(65 + $i), 'focus_area' => $focus, 'exercises' => $exercises];
            }
            $recommendations[] = [
                'key' => $key, 'name' => $preset['name'].' — '.$dayCount.' '.($dayCount === 1 ? 'Day' : 'Days'),
                'description' => $preset['description'], 'equipment' => in_array($key, ['endurance', 'mobility'], true) && ! $gym && ! in_array('Full Body', $focuses, true) ? 'Bodyweight' : $equipment,
                'level' => UserProfile::LEVELS[$profile->experience_level], 'duration' => $profile->session_duration_minutes,
                'reason' => $goalMatch ? 'Matches your '.strtolower(UserProfile::GOALS[$primary]).' goal. '.$this->combinationReason($primary, $secondary) : 'An alternative to your main goal, adapted to your equipment, experience, available days, and session length.',
                'reasons' => [
                    'Primary: '.UserProfile::GOALS[$primary],
                    ...($secondary ? ['Secondary: '.UserProfile::GOALS[$secondary]] : []),
                    'Equipment access: '.implode(', ', array_map(fn ($item) => UserProfile::EQUIPMENT[$item], $profile->equipment_access)),
                    UserProfile::LEVELS[$profile->experience_level],
                    $dayCount.' training days within your '.$profile->training_days_per_week.' available days',
                    'Adapted for '.$profile->session_duration_minutes.'-minute sessions',
                ],
                'days' => $days, 'score' => $score,
            ];
        }
        usort($recommendations, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($recommendations, 0, 3);
    }

    private function combinationReason(string $primary, ?string $secondary): string
    {
        if (! $secondary) {
            return 'Exercises are adapted to your equipment and session length.';
        }
        if (in_array($primary, ['build_muscle', 'get_stronger'], true) && in_array($secondary, ['lose_fat', 'endurance'], true)) {
            return 'Resistance training stays central, with supporting conditioning.';
        }
        if ($primary === 'build_muscle' && $secondary === 'get_stronger') {
            return 'Muscle-building work with lower-rep opening strength exercises.';
        }
        if ($primary === 'get_stronger' && $secondary === 'build_muscle') {
            return 'Strength leads the lower-rep resistance work, with supporting muscle-building exercises.';
        }
        if ($primary === 'mobility') {
            return 'Mobility remains the focus, with supporting full-body training.';
        }
        if (in_array($primary, ['lose_fat', 'endurance', 'general_fitness'], true)) {
            return 'Conditioning and resistance work support your combined goals.';
        }

        return 'Your primary goal leads; the secondary goal guides supporting work.';
    }

    private function exerciseKeys(string $focus, bool $gym, bool $dumbbells): array
    {
        $push = $gym ? 'Barbell_Bench_Press_-_Medium_Grip' : ($dumbbells ? 'Dumbbell_Bench_Press' : 'Pushups');
        $pull = $gym ? 'Seated_Cable_Rows' : ($dumbbells ? 'One-Arm_Dumbbell_Row' : 'Superman');
        $legs = $gym ? 'Leg_Press' : ($dumbbells ? 'Dumbbell_Squat' : 'Bodyweight_Squat');

        return match ($focus) {
            'Push' => [$push, 'Dumbbell_Shoulder_Press', $gym ? 'Triceps_Pushdown' : 'Standing_Dumbbell_Triceps_Extension', 'Side_Lateral_Raise', 'Plank'],
            'Pull' => [$pull, $gym ? 'Wide-Grip_Lat_Pulldown' : 'Superman', 'Dumbbell_Bicep_Curl', 'Plank', 'Cat_Stretch'],
            'Chest' => [$push, $pull, $dumbbells || $gym ? 'Dumbbell_Shoulder_Press' : 'Push_Up_to_Side_Plank', 'Plank', 'Cat_Stretch'],
            'Back' => [$pull, 'Superman', 'Plank', 'Cat_Stretch'],
            'Legs' => [$legs, $gym ? 'Seated_Leg_Curl' : 'Bodyweight_Walking_Lunge', 'Standing_Hip_Circles', 'Hamstring_Stretch'],
            'Cardio' => [$gym ? 'Running_Treadmill' : 'Trail_Running_Walking', 'Bodyweight_Squat', 'Plank', 'Hamstring_Stretch'],
            'Core' => ['Plank', '3_4_Sit-Up', 'Superman', 'Cat_Stretch'],
            'Mobility' => ['Cat_Stretch', 'Childs_Pose', 'Hamstring_Stretch', 'Standing_Hip_Circles'],
            default => [$legs, $push, $pull, 'Plank', 'Hamstring_Stretch'],
        };
    }
}
