<?php

namespace App\Support;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class SeoCanonicalPath
{
    public static function isSafeInternalPath(string $path): bool
    {
        return preg_match('#^/(?!/)[A-Za-z0-9/_-]*$#', $path) === 1;
    }

    public static function matchesEntity(Model $entity, string $path): bool
    {
        if ($entity instanceof Product) {
            return $path === '/products/'.$entity->slug;
        }

        if ($entity instanceof Category) {
            return $path === '/category/'.$entity->slug;
        }

        // The public route pattern for content pages is not defined centrally.
        return $entity instanceof ContentPage;
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
