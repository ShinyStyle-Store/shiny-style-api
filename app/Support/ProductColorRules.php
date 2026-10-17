<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

final class ProductColorRules
{
    private const COLOR_LABELS = ['color', 'colors', 'colour', 'colours', 'لون', 'اللون', 'ألوان', 'الوان'];

    public static function isColorOption(string $code, ?string $nameAr, ?string $nameEn): bool
    {
        return self::isColorLabel($code)
            || self::isColorLabel((string) $nameAr)
            || self::isColorLabel((string) $nameEn);
    }

    public static function validateOption(
        Validator $validator,
        string $code,
        ?string $nameAr,
        ?string $nameEn,
        string $field = 'code',
    ): void {
        if (self::isColorOption($code, $nameAr, $nameEn) && $code !== 'color') {
            $validator->errors()->add($field, 'Color options must use the canonical code "color".');
        }
    }

    public static function validateValue(
        Validator $validator,
        string $code,
        string $valueAr,
        string $valueEn,
        string $field,
        ?int $ignoreValueId = null,
    ): void {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $code) !== 1) {
            $validator->errors()->add($field, 'Color value codes must use lowercase kebab-case.');

            return;
        }

        $query = DB::table('product_option_values as color_values')
            ->join('product_options as color_options', 'color_options.id', '=', 'color_values.product_option_id')
            ->where('color_options.code', 'color')
            ->where('color_values.code', $code)
            ->select('color_values.id', 'color_values.value_ar_normalized', 'color_values.value_en_normalized');

        if ($ignoreValueId !== null) {
            $query->where('color_values.id', '<>', $ignoreValueId);
        }

        $arabic = trim($valueAr);
        $english = mb_strtolower(trim($valueEn));
        foreach ($query->get() as $existing) {
            if ($existing->value_ar_normalized !== $arabic || $existing->value_en_normalized !== $english) {
                $validator->errors()->add($field, 'This color code is already registered with different Arabic or English names.');

                return;
            }
        }
    }

    private static function isColorLabel(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));

        return in_array($normalized, self::COLOR_LABELS, true);
    }
}
