<?php

return [
    'schedule_horizon_days' => 90,
    'limits' => ['reps' => 10000, 'duration_seconds' => 86400, 'weight_kg' => 9999.99],
    'progression' => ['workouts' => 2, 'weight_increment' => 2.5, 'rep_increment' => 1, 'duration_increment' => 5],
    'icons' => array_column(json_decode(file_get_contents(resource_path('data/training-icons.json')), true, flags: JSON_THROW_ON_ERROR), 'id'),
];
