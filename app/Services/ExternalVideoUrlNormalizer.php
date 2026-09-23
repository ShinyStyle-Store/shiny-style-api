<?php

namespace App\Services;

use InvalidArgumentException;

final class ExternalVideoUrlNormalizer
{
    private const MAX_LENGTH = 2048;

    /**
     * @throws InvalidArgumentException when the URL is not a supported video URL.
     */
    public function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('The video URL is invalid.');
        }

        $parts = parse_url($value);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new InvalidArgumentException('The video URL is invalid.');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $path = trim((string) ($parts['path'] ?? ''), '/');

        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            return $this->youtube($parts, $path);
        }

        if ($host === 'youtu.be') {
            return $this->canonicalYoutube($path);
        }

        if (in_array($host, ['youtube-nocookie.com', 'www.youtube-nocookie.com'], true)) {
            return $this->youtubeEmbed($path);
        }

        if (in_array($host, ['vimeo.com', 'www.vimeo.com'], true)) {
            return $this->vimeo($path);
        }

        if ($host === 'player.vimeo.com') {
            return $this->vimeoPlayer($path);
        }

        throw new InvalidArgumentException('The video URL is invalid.');
    }

    /** @param array<string, mixed> $parts */
    private function youtube(array $parts, string $path): string
    {
        if ($path === 'watch') {
            parse_str((string) ($parts['query'] ?? ''), $query);
            return $this->canonicalYoutube(is_string($query['v'] ?? null) ? $query['v'] : '');
        }

        return $this->youtubeEmbed($path);
    }

    private function youtubeEmbed(string $path): string
    {
        foreach (['embed', 'shorts'] as $prefix) {
            if (str_starts_with($path, $prefix.'/')) {
                return $this->canonicalYoutube(substr($path, strlen($prefix) + 1));
            }
        }

        throw new InvalidArgumentException('The video URL is invalid.');
    }

    private function canonicalYoutube(string $id): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            throw new InvalidArgumentException('The video URL is invalid.');
        }

        return 'https://www.youtube-nocookie.com/embed/'.$id;
    }

    private function vimeo(string $path): string
    {
        if (! preg_match('/^[0-9]+$/', $path)) {
            throw new InvalidArgumentException('The video URL is invalid.');
        }

        return 'https://player.vimeo.com/video/'.$path;
    }

    private function vimeoPlayer(string $path): string
    {
        if (! str_starts_with($path, 'video/')) {
            throw new InvalidArgumentException('The video URL is invalid.');
        }

        return $this->vimeo(substr($path, 6));
    }
}
