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
        'homeSeoTitle' => ['key' => 'home_seo_title', 'localized' => true, 'type' => 'title'],
        'homeMetaDescription' => ['key' => 'home_meta_description', 'localized' => true, 'type' => 'description'],
        'homeRobotsPolicy' => ['key' => 'home_robots_policy', 'localized' => false, 'type' => 'robots_policy'],
        'homeOgImageId' => ['key' => 'home_og_image_id', 'localized' => false, 'type' => 'image'],
        'shopSeoTitle' => ['key' => 'shop_seo_title', 'localized' => true, 'type' => 'title'],
        'shopMetaDescription' => ['key' => 'shop_meta_description', 'localized' => true, 'type' => 'description'],
        'shopRobotsPolicy' => ['key' => 'shop_robots_policy', 'localized' => false, 'type' => 'robots_policy'],
        'shopOgImageId' => ['key' => 'shop_og_image_id', 'localized' => false, 'type' => 'image'],
        'contactSeoTitle' => ['key' => 'contact_seo_title', 'localized' => true, 'type' => 'title'],
        'contactMetaDescription' => ['key' => 'contact_meta_description', 'localized' => true, 'type' => 'description'],
        'contactRobotsPolicy' => ['key' => 'contact_robots_policy', 'localized' => false, 'type' => 'robots_policy'],
        'contactOgImageId' => ['key' => 'contact_og_image_id', 'localized' => false, 'type' => 'image'],
    ];

    /** @var array<string, array{canonicalPath: string, titleSetting: string, descriptionSetting: string, robotsSetting: string, imageSetting: string}> */
    public const PUBLIC_PAGES = [
        'home' => [
            'canonicalPath' => '/',
            'titleSetting' => 'homeSeoTitle',
            'descriptionSetting' => 'homeMetaDescription',
            'robotsSetting' => 'homeRobotsPolicy',
            'imageSetting' => 'homeOgImageId',
        ],
        'shop' => [
            'canonicalPath' => '/shop',
            'titleSetting' => 'shopSeoTitle',
            'descriptionSetting' => 'shopMetaDescription',
            'robotsSetting' => 'shopRobotsPolicy',
            'imageSetting' => 'shopOgImageId',
        ],
        'contact' => [
            'canonicalPath' => '/contact',
            'titleSetting' => 'contactSeoTitle',
            'descriptionSetting' => 'contactMetaDescription',
            'robotsSetting' => 'contactRobotsPolicy',
            'imageSetting' => 'contactOgImageId',
        ],
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
