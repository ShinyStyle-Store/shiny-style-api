<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Product;
use App\Support\SeoCanonicalPath;
use App\Support\SeoRedirectPath;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SeoRevalidationPayloadFactory
{
    private const ENTITY_TYPES = ['product', 'category', 'page', 'store', 'offer', 'redirect'];

    private const REASONS = [
        'created', 'updated', 'slug_changed', 'published', 'unpublished',
        'archived', 'restored', 'deleted', 'time_boundary',
    ];

    private const SCOPES = ['catalog', 'content', 'store', 'sitemap', 'redirects', 'metaFeed'];

    /**
     * @param list<string> $locales
     * @param list<string> $scopes
     * @param list<string> $additionalPaths
     * @return array{version: int, eventId: string, occurredAt: string, entityType: string, entityId: string, reason: string, locales: list<string>, scopes: list<string>, paths: list<string>}
     */
    public function forEntity(
        Model $entity,
        string $reason,
        array $locales,
        array $scopes,
        array $additionalPaths = [],
        ?CarbonImmutable $occurredAt = null,
    ): array {
        $type = match (true) {
            $entity instanceof Product => 'product',
            $entity instanceof Category => 'category',
            $entity instanceof ContentPage => 'page',
            default => throw new InvalidArgumentException('Unsupported SEO revalidation entity.'),
        };
        $paths = $additionalPaths;
        $canonicalPath = SeoCanonicalPath::expectedPath($entity);
        if ($canonicalPath !== null) {
            foreach ($locales as $locale) {
                $paths[] = '/'.$locale.$canonicalPath;
            }
        }

        return $this->make($type, (string) $entity->getKey(), $reason, $locales, $scopes, $paths, $occurredAt);
    }

    /**
     * @param list<string> $locales
     * @param list<string> $scopes
     * @param list<string> $paths
     * @return array{version: int, eventId: string, occurredAt: string, entityType: string, entityId: string, reason: string, locales: list<string>, scopes: list<string>, paths: list<string>}
     */
    public function make(
        string $entityType,
        string $entityId,
        string $reason,
        array $locales,
        array $scopes,
        array $paths = [],
        ?CarbonImmutable $occurredAt = null,
    ): array {
        $locales = array_values(array_unique($locales));
        $scopes = array_values(array_unique($scopes));
        $paths = array_values(array_unique($paths));

        if (! in_array($entityType, self::ENTITY_TYPES, true)
            || $entityId === ''
            || strlen($entityId) > 64
            || ! in_array($reason, self::REASONS, true)
            || $locales === []
            || array_diff($locales, ['ar', 'en']) !== []
            || $scopes === []
            || array_diff($scopes, self::SCOPES) !== []
            || count($scopes) > 6
            || count($paths) > 50) {
            throw new InvalidArgumentException('The SEO revalidation event is invalid.');
        }

        foreach ($paths as $path) {
            if (strlen($path) > 1024
                || preg_match('#^/(?!/)[A-Za-z0-9/_-]+$#', $path) !== 1
                || SeoRedirectPath::normalize($path) !== $path) {
                throw new InvalidArgumentException('The SEO revalidation path is invalid.');
            }
        }

        $payload = [
            'version' => 1,
            'eventId' => (string) Str::uuid(),
            'occurredAt' => ($occurredAt ?? CarbonImmutable::now('UTC'))->utc()->toIso8601ZuluString(),
            'entityType' => $entityType,
            'entityId' => $entityId,
            'reason' => $reason,
            'locales' => $locales,
            'scopes' => $scopes,
            'paths' => $paths,
        ];

        if (strlen(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 16 * 1024) {
            throw new InvalidArgumentException('The SEO revalidation event exceeds the contract body limit.');
        }

        return $payload;
    }
}
