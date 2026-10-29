<?php

// Authored block prescriptions; weights remain the member's choice.
$templates = collect(require __DIR__.'/training-plans.php')->keyBy('key');
$block = function (array $days, string $name, int $from, int $to, string $type, array $main, array $accessory, string $description) {
    $weekdays = match (count($days)) {
        3 => [1, 3, 5], 4 => [1, 2, 4, 6], default => [0, 1, 3, 4, 6]
    };
    foreach ($days as $i => &$day) {
        $day['weekdays'] = [$weekdays[$i]];
        foreach ($day['exercises'] as $j => &$exercise) {
            if ($exercise['tracking_mode'] === 'duration') {
                if ($type === 'deload') {
                    $exercise['sets'] = 1;
                    $exercise['reps'] = str_contains($exercise['reps'], 'minutes') ? '15 minutes' : '20 seconds';
                }

                continue;
            }
            [$exercise['sets'], $exercise['reps']] = $j === 0 ? $main : $accessory;
        }
        unset($exercise);
    }

    return ['name' => $name, 'start_week' => $from, 'end_week' => $to, 'type' => $type, 'description' => $description, 'days' => $days];
};
$program = function (string $source, string $key, string $name, int $weeks, array $blocks) use ($templates) {
    return [...$templates[$source], 'key' => $key, 'name' => $name, 'structure_type' => 'program', 'duration_weeks' => $weeks, 'blocks' => $blocks,
        'description' => $templates[$source]['description'].' Follow a finite sequence of training blocks, finishing with an explicitly lighter deload.'];
};
$beginner = $templates['gym-foundations']['days'];
$hypertrophy = $templates['upper-lower']['days'];
$recomp = $templates['mixed-fitness']['days'];
$power = $templates['powerbuilding']['days'];

return [
    $program('gym-foundations', 'program-beginner-strength', '8-Week Beginner Strength', 8, [
        $block($beginner, 'Foundation', 1, 4, 'normal', [2, '8–12'], [2, '10–12'], 'Practice the movements with manageable working weights.'),
        $block($beginner, 'Build', 5, 7, 'normal', [3, '6–8'], [3, '8–12'], 'Add a working set and focus on steady, controlled repetitions.'),
        $block($beginner, 'Deload', 8, 8, 'deload', [2, '8–10'], [1, '10–12'], 'Lower volume while keeping familiar movements.'),
    ]),
    $program('upper-lower', 'program-hypertrophy', '12-Week Hypertrophy', 12, [
        $block($hypertrophy, 'Foundation Volume', 1, 4, 'normal', [3, '8–10'], [3, '10–12'], 'Establish repeatable upper and lower sessions.'),
        $block($hypertrophy, 'Build', 5, 8, 'normal', [4, '6–8'], [3, '10–15'], 'More main-lift volume with supporting muscle work.'),
        $block($hypertrophy, 'Progress', 9, 11, 'normal', [4, '5–6'], [4, '8–12'], 'Lower main-lift reps and a focused accessory workload.'),
        $block($hypertrophy, 'Deload', 12, 12, 'deload', [2, '8–10'], [2, '10–12'], 'A deliberately lighter final week.'),
    ]),
    $program('mixed-fitness', 'program-recomposition', '10-Week Body Recomposition', 10, [
        $block($recomp, 'Consistency', 1, 3, 'normal', [3, '8–10'], [2, '10–12'], 'Three resistance days and a dedicated conditioning day.'),
        $block($recomp, 'Build', 4, 6, 'normal', [4, '6–8'], [3, '10–12'], 'Build resistance volume while retaining conditioning.'),
        $block($recomp, 'Consolidate', 7, 9, 'normal', [3, '6–8'], [3, '12–15'], 'Keep main lifts consistent and extend accessory rep ranges.'),
        $block($recomp, 'Deload', 10, 10, 'deload', [2, '8–10'], [1, '10–12'], 'Reduce lifting volume and conditioning duration.'),
    ]),
    $program('powerbuilding', 'program-powerbuilding', '12-Week Powerbuilding', 12, [
        $block($power, 'Foundation', 1, 3, 'normal', [3, '6–8'], [3, '8–12'], 'Balance the main lifts with supporting volume.'),
        $block($power, 'Build', 4, 7, 'normal', [4, '4–6'], [3, '8–10'], 'Four working sets on the main movements.'),
        $block($power, 'Strength', 8, 11, 'normal', [4, '3–5'], [3, '6–10'], 'Lower-rep main lifts with full rest between sets.'),
        $block($power, 'Deload', 12, 12, 'deload', [2, '6–8'], [2, '10–12'], 'Reduced volume; select comfortable working weights.'),
    ]),
];
