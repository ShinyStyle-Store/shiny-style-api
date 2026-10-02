<?php

namespace App\Support;

final class ProductFeatures
{
    public static function normalizeAdminInput(mixed $value): mixed
    {
        if (! self::isListOfArrays($value)) {
            return $value;
        }

        return array_map(static function (array $feature): array {
            foreach (['ar', 'en'] as $locale) {
                if (is_string($feature[$locale] ?? null)) {
                    $feature[$locale] = trim($feature[$locale]) ?: null;
                }
            }

            return $feature;
        }, $value);
    }

    public static function localize(mixed $value): mixed
    {
        if (self::isPairedList($value)) {
            return array_map(
                static fn (array $feature): ?string => self::localizedValue($feature['ar'] ?? null, $feature['en'] ?? null),
                $value,
            );
        }

        if (is_array($value) && (! array_is_list($value))
            && (array_key_exists('ar', $value) || array_key_exists('en', $value))) {
            return self::localizedValue($value['ar'] ?? null, $value['en'] ?? null);
        }

        return $value;
    }

    public static function isPairedList(mixed $value): bool
    {
        if (! self::isListOfArrays($value)) {
            return false;
        }

        foreach ($value as $feature) {
            $keys = array_keys($feature);
            sort($keys);
            if ($keys !== ['ar', 'en']
                || (! is_string($feature['ar']) && $feature['ar'] !== null)
                || (! is_string($feature['en']) && $feature['en'] !== null)) {
                return false;
            }
        }

        return true;
    }

    private static function isListOfArrays(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && $value !== []
            && array_reduce($value, static fn (bool $valid, mixed $feature): bool => $valid && is_array($feature), true);
    }

    private static function localizedValue(mixed $arabic, mixed $english): mixed
    {
        if (app()->getLocale() === 'en') {
            return $english !== null && $english !== '' ? $english : ($arabic !== '' ? $arabic : null);
        }

        return $arabic !== null && $arabic !== '' ? $arabic : ($english !== '' ? $english : null);
    }
}
