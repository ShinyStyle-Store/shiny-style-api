<?php

return [
    'enabled' => env('SEO_REVALIDATION_ENABLED', false),
    'secret' => env('SEO_REVALIDATION_SECRET'),
    'url' => env('SEO_REVALIDATION_URL'),
    'queue_connection' => env('SEO_REVALIDATION_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
    'queue' => env('SEO_REVALIDATION_QUEUE', 'seo-revalidation'),
    'connect_timeout_seconds' => (int) env('SEO_REVALIDATION_CONNECT_TIMEOUT_SECONDS', 2),
    'timeout_seconds' => (int) env('SEO_REVALIDATION_TIMEOUT_SECONDS', 5),
];
