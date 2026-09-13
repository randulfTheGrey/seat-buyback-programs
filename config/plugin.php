<?php

declare(strict_types=1);

return [
    'route_prefix' => 'buyback',
    'manager_route_prefix' => 'buyback-manage',
    'admin_route_prefix' => 'buyback-admin',

    'appraisal' => [
        'max_unique_types' => 500,
        'max_input_lines' => 2000,
        'max_input_bytes' => 262144,
        'cache_prefix' => 'seat-buyback-programs:appraisal:',
        'creation_lock_seconds' => 15,
        'creation_lock_wait_seconds' => 5,
    ],

    'compression' => [
        'sde_base_url' => 'https://developers.eveonline.com/static-data/tranquility',
        'connect_timeout_seconds' => 10,
        'download_timeout_seconds' => 180,
        'sync_lock_seconds' => 600,
    ],
];
