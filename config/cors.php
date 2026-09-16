<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
    ],

    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
        static fn (string $origin): bool => $origin !== '' && ! str_contains($origin, '*'),
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Accept-Language',
        'X-Requested-With',
        'Idempotency-Key',
    ],

    'exposed_headers' => [
        'Content-Language',
    ],

    'max_age' => 600,

    'supports_credentials' => false,
];
