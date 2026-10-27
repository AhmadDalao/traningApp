<?php

return [
    'icons' => array_column(json_decode(file_get_contents(resource_path('data/training-icons.json')), true, flags: JSON_THROW_ON_ERROR), 'id'),
];
