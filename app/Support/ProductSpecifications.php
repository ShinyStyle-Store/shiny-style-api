<?php

namespace App\Support;

final class ProductSpecifications
{
    public static function normalizeAdminInput(mixed $value): mixed
    {
        if (! self::isListOfArrays($value)) {
            return $value;
        }

        return array_map(static function (array $specification): array {
            foreach (['ar', 'en'] as $locale) {
                if (! is_array($specification[$locale] ?? null)) {
                    continue;
                }
                foreach (['label', 'value'] as $field) {
                    if (is_string($specification[$locale][$field] ?? null)) {
                        $specification[$locale][$field] = trim($specification[$locale][$field]) ?: null;
                    }
                }
            }

            return $specification;
        }, $value);
    }

    public static function localize(mixed $value): mixed
    {
        if (self::isPairedList($value)) {
            $localized = [];
            foreach ($value as $specification) {
                $translation = self::completeTranslation($specification[app()->getLocale()] ?? null)
                    ? $specification[app()->getLocale()]
                    : (self::completeTranslation($specification[app()->getLocale() === 'en' ? 'ar' : 'en'] ?? null)
                        ? $specification[app()->getLocale() === 'en' ? 'ar' : 'en']
                        : null);
                if ($translation !== null) {
                    $localized[$translation['label']] = $translation['value'];
                }
            }

            return $localized;
        }

        if (is_array($value) && ! array_is_list($value)
            && (array_key_exists('ar', $value) || array_key_exists('en', $value))) {
            return self::legacyLocalizedValue($value['ar'] ?? null, $value['en'] ?? null);
        }

        return $value;
    }

    public static function isPairedList(mixed $value): bool
    {
        if (! self::isListOfArrays($value)) {
            return false;
        }

        foreach ($value as $specification) {
            $keys = array_keys($specification);
            sort($keys);
            if ($keys !== ['ar', 'en']
                || ! self::isTranslationShape($specification['ar'])
                || ! self::isTranslationShape($specification['en'])) {
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
            && array_reduce($value, static fn (bool $valid, mixed $specification): bool => $valid && is_array($specification), true);
    }

    private static function isTranslationShape(mixed $translation): bool
    {
        if (! is_array($translation)) {
            return false;
        }
        $keys = array_keys($translation);
        sort($keys);

        return $keys === ['label', 'value']
            && (is_string($translation['label']) || $translation['label'] === null)
            && (is_string($translation['value']) || $translation['value'] === null);
    }

    private static function completeTranslation(mixed $translation): bool
    {
        return self::isTranslationShape($translation)
            && is_string($translation['label']) && $translation['label'] !== ''
            && is_string($translation['value']) && $translation['value'] !== '';
    }

    private static function legacyLocalizedValue(mixed $arabic, mixed $english): mixed
    {
        if (app()->getLocale() === 'en') {
            return $english !== null && $english !== '' ? $english : ($arabic !== '' ? $arabic : null);
        }

        return $arabic !== null && $arabic !== '' ? $arabic : ($english !== '' ? $english : null);
    }
}
