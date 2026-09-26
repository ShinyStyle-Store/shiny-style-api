<?php

use Illuminate\Http\Request;

$configuredProxies = env('TRUSTED_PROXIES');

if (is_string($configuredProxies)) {
    $configuredProxies = trim($configuredProxies);
    $configuredProxies = $configuredProxies === ''
        ? null
        : (str_contains($configuredProxies, ',')
            ? array_values(array_filter(array_map('trim', explode(',', $configuredProxies))))
            : $configuredProxies);
}

return [
    'proxies' => $configuredProxies,
    'headers' => Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT,
];
