<?php

namespace App\Support;

final class SeoCanonicalOrigin
{
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($value);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || ! self::isPublicHost($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return null;
        }

        return rtrim($value, '/');
    }

    public static function domainScope(mixed $value): ?string
    {
        $origin = self::normalize($value);
        if ($origin === null) {
            return null;
        }

        $parts = parse_url($origin);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            return null;
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        $port = isset($parts['port']) && (int) $parts['port'] !== 443
            ? ':'.(int) $parts['port']
            : '';

        return $host.$port;
    }

    public static function isPublicHost(string $host): bool
    {
        $host = strtolower(rtrim(trim($host, '[]'), '.'));
        if ($host === 'localhost'
            || preg_match('/\.(?:localhost|local|internal|test|invalid|home\.arpa)$/', $host) === 1) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return str_contains($host, '.');
    }
}
