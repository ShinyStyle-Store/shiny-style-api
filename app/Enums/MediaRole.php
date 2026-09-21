<?php

namespace App\Enums;

final class MediaRole
{
    public const CATEGORY_COVER = 'category_cover';

    public const PRODUCT_IMAGE = 'product_image';

    public const PRODUCT_VIDEO = 'product_video';

    public const VARIANT_IMAGE = 'variant_image';

    public const VARIANT_VIDEO = 'variant_video';

    public const IMAGE_ROLES = [self::CATEGORY_COVER, self::PRODUCT_IMAGE, self::VARIANT_IMAGE];

    public const VIDEO_ROLES = [self::PRODUCT_VIDEO, self::VARIANT_VIDEO];

    /** @return list<string> */
    public static function forOwner(string $owner): array
    {
        return match ($owner) {
            \App\Models\Category::class => [self::CATEGORY_COVER],
            \App\Models\Product::class => [self::PRODUCT_IMAGE, self::PRODUCT_VIDEO],
            \App\Models\SellableItem::class => [self::VARIANT_IMAGE, self::VARIANT_VIDEO],
            default => [],
        };
    }

    public static function mediaType(string $role): ?string
    {
        return match (true) {
            in_array($role, self::IMAGE_ROLES, true) => 'image',
            in_array($role, self::VIDEO_ROLES, true) => 'video',
            default => null,
        };
    }

    public static function supports(string $owner, string $role): bool
    {
        return in_array($role, self::forOwner($owner), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return [...self::IMAGE_ROLES, ...self::VIDEO_ROLES];
    }
}
