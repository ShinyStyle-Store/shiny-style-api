<?php

return [
    'reservation_minutes' => max(1, (int) env('PAYMENT_RESERVATION_MINUTES', 30)),
    'expiration_batch_size' => max(1, min(1000, (int) env('PAYMENT_EXPIRATION_BATCH_SIZE', 100))),
    'initiation_url_minutes' => max(1, (int) env('PAYMENT_INITIATION_URL_MINUTES', 30)),
    'status_url_minutes' => max(30, (int) env('PAYMENT_STATUS_URL_MINUTES', 1440)),
    'return_token_minutes' => max(5, min(1440, (int) env('PAYMENT_RETURN_TOKEN_MINUTES', 120))),
    // Paymob's public documentation does not state the redirect URL limit; 128 is the conservative application bound.
    'redirection_url_max_length' => max(64, min(128, (int) env('PAYMOB_REDIRECTION_URL_MAX_LENGTH', 128))),
];
