<?php

namespace App\Enums;

final class MediaRole
{
    public const CATEGORY_COVER = 'category_cover';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::CATEGORY_COVER];
    }
}
