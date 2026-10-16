<?php

namespace App\Support;

final class SeoSitemapCursor
{
    public static function encode(string $type, int $id): string
    {
        $payload = json_encode(['v' => 1, 'type' => $type, 'id' => $id], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    public static function decode(string $cursor, string $type): ?int
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/', $cursor) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/').str_repeat('=', (4 - strlen($cursor) % 4) % 4), true);
        if (! is_string($decoded)) {
            return null;
        }

        try {
            $payload = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['v'] ?? null) !== 1
            || ($payload['type'] ?? null) !== $type
            || ! is_int($payload['id'] ?? null)
            || $payload['id'] < 1) {
            return null;
        }

        return $payload['id'];
    }
}
