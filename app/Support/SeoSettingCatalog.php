<?php

namespace App\Support;

final class SeoSettingCatalog
{
    /** @var array<string, array{key: string, localized: bool, type: string}> */
    public const DEFINITIONS = [
        'storeName' => ['key' => 'store_name', 'localized' => true, 'type' => 'string'],
        'defaultSeoTitle' => ['key' => 'default_seo_title', 'localized' => true, 'type' => 'title'],
        'defaultMetaDescription' => ['key' => 'default_meta_description', 'localized' => true, 'type' => 'description'],
        'defaultOgImageId' => ['key' => 'default_og_image_id', 'localized' => false, 'type' => 'image'],
        'storeLogoId' => ['key' => 'store_logo_id', 'localized' => false, 'type' => 'image'],
        'canonicalBaseUrl' => ['key' => 'canonical_base_url', 'localized' => false, 'type' => 'https_origin'],
        'supportedLocales' => ['key' => 'supported_locales', 'localized' => false, 'type' => 'locales'],
        'defaultRobotsPolicy' => ['key' => 'default_robots_policy', 'localized' => false, 'type' => 'robots_policy'],
    ];

    /** @return array{key: string, localized: bool, type: string}|null */
    public static function definition(string $apiKey): ?array
    {
        return self::DEFINITIONS[$apiKey] ?? null;
    }

    public static function apiKey(string $key): ?string
    {
        foreach (self::DEFINITIONS as $apiKey => $definition) {
            if ($definition['key'] === $key) {
                return $apiKey;
            }
        }

        return null;
    }
}
