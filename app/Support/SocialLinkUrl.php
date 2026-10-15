<?php

namespace App\Support;

final class SocialLinkUrl
{
    public static function isValidForPlatform(string $platform, string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHosts = config("store.social_platforms.{$platform}.hosts", []);
        $path = (string) ($parts['path'] ?? '');
        $requiredPathPattern = config("store.social_platforms.{$platform}.required_path_pattern");
        $forbiddenPathPattern = config("store.social_platforms.{$platform}.forbidden_path_pattern");
        $hasRequiredPath = $requiredPathPattern === null
            || preg_match($requiredPathPattern, $path) === 1;
        $hasForbiddenPath = $forbiddenPathPattern !== null
            && preg_match($forbiddenPathPattern, $path) === 1;
        $port = $parts['port'] ?? null;

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_array($allowedHosts)
            && in_array($host, $allowedHosts, true)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ($port === null || (int) $port === 443)
            && $hasRequiredPath
            && ! $hasForbiddenPath;
    }
}
