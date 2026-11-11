<?php

namespace App\Support;

final class EgyptianPhone
{
    public static function normalize(mixed $phone): mixed
    {
        if (! is_string($phone)) {
            return $phone;
        }

        $phone = preg_replace_callback(
            '/[\\x{0660}-\\x{0669}\\x{06F0}-\\x{06F9}]/u',
            static function (array $matches): string {
                $bytes = array_values(unpack('C*', $matches[0]));
                $codepoint = (($bytes[0] & 0x1F) << 6) | ($bytes[1] & 0x3F);
                $zeroCodepoint = $codepoint >= 0x06F0 ? 0x06F0 : 0x0660;

                return (string) ($codepoint - $zeroCodepoint);
            },
            $phone,
        ) ?? $phone;
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
