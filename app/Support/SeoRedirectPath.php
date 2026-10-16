<?php

namespace App\Support;

final class SeoRedirectPath
{
    /** @return array{locale: string, entityType: string, slug: string}|null */
    public static function parse(mixed $value): ?array
    {
        if (! is_string($value)
            || $value === ''
            || $value !== trim($value)
            || strlen($value) > 2048) {
            return null;
        }

        if (preg_match('#^/(ar|en)/(products|category)/([a-z0-9]+(?:-[a-z0-9]+)*)$#', $value, $matches) === 1) {
            return [
                'locale' => $matches[1],
                'entityType' => $matches[2] === 'products' ? 'product' : 'category',
                'slug' => $matches[3],
            ];
        }

        if (preg_match('#^/(ar|en)(/.+)$#', $value, $matches) === 1) {
            $slug = SeoCanonicalPath::contentPageSlugForPath($matches[2]);
            if ($slug !== null) {
                return ['locale' => $matches[1], 'entityType' => 'content_page', 'slug' => $slug];
            }
        }

        return null;
    }

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)
            || str_contains($value, '\\')
            || str_contains($value, '%')
            || str_contains($value, '?')
            || str_contains($value, '#')
            || str_contains($value, '//')) {
            return null;
        }

        return self::parse($value) === null ? null : $value;
    }

    public static function productPath(string $locale, string $slug): string
    {
        return '/'.$locale.'/products/'.$slug;
    }

    public static function categoryPath(string $locale, string $slug): string
    {
        return '/'.$locale.'/category/'.$slug;
    }
}
