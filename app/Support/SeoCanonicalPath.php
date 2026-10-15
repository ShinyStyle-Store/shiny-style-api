<?php

namespace App\Support;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class SeoCanonicalPath
{
    /** @var array<string, string> */
    private const CONTENT_PAGE_PATHS = [
        'about-us' => '/about',
        'returns-policy' => '/return-policy',
        'shipping-policy' => '/shipping-policy',
    ];

    public static function isSafeInternalPath(string $path): bool
    {
        return preg_match('#^/(?!/)[A-Za-z0-9/_-]*$#', $path) === 1;
    }

    public static function matchesEntity(Model $entity, string $path): bool
    {
        return self::expectedPath($entity) === $path;
    }

    public static function expectedPath(Model $entity): ?string
    {
        if ($entity instanceof Product) {
            return '/products/'.$entity->slug;
        }

        if ($entity instanceof Category) {
            return '/category/'.$entity->slug;
        }

        if ($entity instanceof ContentPage) {
            return self::CONTENT_PAGE_PATHS[$entity->slug] ?? null;
        }

        return null;
    }

    public static function validateForEntity(Model $entity, ?string $path): void
    {
        if ($path === null) {
            return;
        }

        if (! self::isSafeInternalPath($path)) {
            throw ValidationException::withMessages([
                'canonicalPath' => 'The canonical path must be a safe root-relative path.',
            ]);
        }

        if (! self::matchesEntity($entity, $path)) {
            throw ValidationException::withMessages([
                'canonicalPath' => 'The canonical path does not match the selected SEO entity.',
            ]);
        }
    }
}
