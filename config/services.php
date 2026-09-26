<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paymob' => [
        'secret_key' => env('PAYMOB_SECRET_KEY'),
        'public_key' => env('PAYMOB_PUBLIC_KEY'),
        'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
        'card_integration_id' => env('PAYMOB_CARD_INTEGRATION_ID'),
        'wallet_integration_id' => env('PAYMOB_WALLET_INTEGRATION_ID'),
        'api_base_url' => env('PAYMOB_API_BASE_URL', 'https://accept.paymob.com'),
        'intention_endpoint' => env('PAYMOB_INTENTION_ENDPOINT', '/v1/intention/'),
        'unified_checkout_base_url' => env(
            'PAYMOB_UNIFIED_CHECKOUT_BASE_URL',
            'https://accept.paymob.com/unifiedcheckout/',
        ),
        'redirect_url' => env('PAYMOB_REDIRECT_URL'),
        'webhook_url' => env('PAYMOB_WEBHOOK_URL'),
        'timeout_seconds' => max(1, (int) env('PAYMOB_TIMEOUT_SECONDS', 30)),
        'connect_timeout_seconds' => max(1, (int) env('PAYMOB_CONNECT_TIMEOUT_SECONDS', 10)),
    ],

];
