<?php

namespace App\Support;

final class EgyptianPhone
{
    public static function normalize(mixed $phone): mixed
    {
        if (! is_string($phone)) {
            return $phone;
        }

        $phone = strtr($phone, [
            'Ù ' => '0', 'Ù¡' => '1', 'Ù¢' => '2', 'Ù£' => '3', 'Ù¤' => '4',
            'Ù¥' => '5', 'Ù¦' => '6', 'Ù§' => '7', 'Ù¨' => '8', 'Ù©' => '9',
            'Û°' => '0', 'Û±' => '1', 'Û²' => '2', 'Û³' => '3', 'Û´' => '4',
            'Ûµ' => '5', 'Û¶' => '6', 'Û·' => '7', 'Û¸' => '8', 'Û¹' => '9',
        ]);
        $phone = preg_replace('/[\s\-()]+/', '', trim($phone)) ?? $phone;

        if ($phone === '') {
            return null;
        }

        if (str_starts_with($phone, '+20')) {
            $phone = '0'.substr($phone, 3);
        } elseif (str_starts_with($phone, '0020')) {
            $phone = '0'.substr($phone, 4);
        }

        return $phone;
    }
}
