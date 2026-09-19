<?php

namespace App\Support;

final class AdminAuthMessages
{
    public static function invalidCredentials(): string
    {
        return app()->getLocale() === 'en' ? 'Invalid credentials.' : 'بيانات الدخول غير صحيحة.';
    }

    public static function unauthorized(): string
    {
        return app()->getLocale() === 'en' ? 'Unauthenticated.' : 'يجب تسجيل الدخول.';
    }

    public static function forbidden(): string
    {
        return app()->getLocale() === 'en' ? 'Forbidden.' : 'غير مسموح.';
    }
}
