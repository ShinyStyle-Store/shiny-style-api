<?php

return [
    'social_platforms' => [
        'facebook' => [
            'hosts' => ['facebook.com', 'www.facebook.com'],
            'forbidden_path_pattern' => '#^/groups(?:/|$)#i',
        ],
        'facebook_group' => [
            'hosts' => ['facebook.com', 'www.facebook.com'],
            'required_path_pattern' => '#^/groups/[^/]+(?:/|$)#i',
        ],
        'instagram' => [
            'hosts' => ['instagram.com', 'www.instagram.com'],
        ],
        'tiktok' => [
            'hosts' => ['tiktok.com', 'www.tiktok.com'],
        ],
        'whatsapp' => [
            'hosts' => ['wa.me', 'api.whatsapp.com'],
        ],
    ],
];
