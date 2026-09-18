<?php

namespace App\Support;

final class AdminPasswordPolicy
{
    /** @return list<string> */
    public static function violations(?string $password): array
    {
        $violations = [];

        if ($password === null || strlen($password) < 10) {
            $violations[] = 'Password must be at least 10 characters.';
        }
        if ($password === null || ! preg_match('/[A-Za-z]/', $password)) {
            $violations[] = 'Password must contain letters.';
        }
        if ($password === null || ! preg_match('/[A-Z]/', $password) || ! preg_match('/[a-z]/', $password)) {
            $violations[] = 'Password must contain mixed case.';
        }
        if ($password === null || ! preg_match('/\d/', $password)) {
            $violations[] = 'Password must contain numbers.';
        }
        if ($password === null || ! preg_match('/[^A-Za-z\d]/', $password)) {
            $violations[] = 'Password must contain symbols.';
        }

        return $violations;
    }
}
