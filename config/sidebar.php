<?php

declare(strict_types=1);

return [
    'buyback-programs' => [
        'name' => 'Buyback',
        'icon' => 'fas fa-hand-holding-usd',
        'route_segment' => (string) config('seat-buyback-programs.route_prefix', 'buyback'),
        'permission' => 'randulfthegrey-buyback.request',
        'entries' => [
            [
                'name' => 'New Appraisal',
                'icon' => 'fas fa-calculator',
                'route' => 'buyback.programs.index',
                'permission' => 'randulfthegrey-buyback.request',
            ],
            [
                'name' => 'My Buybacks',
                'icon' => 'fas fa-receipt',
                'route' => 'buyback.requests.index',
                'permission' => 'randulfthegrey-buyback.request',
            ],
        ],
    ],
    'buyback-management' => [
        'name' => 'Manage Buybacks',
        'icon' => 'fas fa-clipboard-check',
        'route_segment' => (string) config('seat-buyback-programs.manager_route_prefix', 'buyback-manage'),
        'permission' => 'randulfthegrey-buyback.manage',
        'entries' => [
            [
                'name' => 'Request Queue',
                'icon' => 'fas fa-tasks',
                'route' => 'buyback.manage.requests.index',
                'permission' => 'randulfthegrey-buyback.manage',
            ],
        ],
    ],
    'buyback-administration' => [
        'name' => 'Buyback Administration',
        'icon' => 'fas fa-cogs',
        'route_segment' => (string) config('seat-buyback-programs.admin_route_prefix', 'buyback-admin'),
        'permission' => 'randulfthegrey-buyback.admin',
        'entries' => [
            [
                'name' => 'Programs',
                'icon' => 'fas fa-list',
                'route' => 'buyback.admin.programs.index',
                'permission' => 'randulfthegrey-buyback.admin',
            ],
            [
                'name' => 'Rule Preview',
                'icon' => 'fas fa-search',
                'route' => 'buyback.admin.preview.index',
                'permission' => 'randulfthegrey-buyback.admin',
            ],
            [
                'name' => 'Reference Data',
                'icon' => 'fas fa-database',
                'route' => 'buyback.admin.reference-data.index',
                'permission' => 'randulfthegrey-buyback.admin',
            ],
        ],
    ],
];
