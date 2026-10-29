<?php

return [
    'enabled' => (bool) env('WEB_PUSH_ENABLED', false),
    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'publicKey' => env('VAPID_PUBLIC_KEY'),
        'privateKey' => env('VAPID_PRIVATE_KEY'),
    ],
    'batch_size' => 50,
    'run_seconds' => 45,
    'timeout' => 5,
    'max_attempts' => 3,
    'lookback_minutes' => 5,
];
