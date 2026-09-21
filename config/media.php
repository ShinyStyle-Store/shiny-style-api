<?php

return [
    'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 'local')),

    'images' => [
        'max_image_size_bytes' => 5 * 1024 * 1024,
        'mime_types' => [
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
        'extensions' => [
            'jpg',
            'jpeg',
            'png',
            'webp',
        ],
        'mime_extensions' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ],
    ],

    'videos' => [
        'max_video_size_bytes' => 100 * 1024 * 1024,
        'mime_types' => [
            'video/mp4',
            'video/quicktime',
            'video/webm',
        ],
        'mime_extensions' => [
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
        ],
    ],
];
