<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetApiLocale
{
    private const API_DEFAULT_LOCALE = 'ar';

    public function handle(Request $request, Closure $next): Response
    {
        $language = trim(explode(',', (string) $request->header('Accept-Language'), 2)[0]);
        $primaryLanguage = strtolower(explode('-', $language, 2)[0]);
        $locale = self::API_DEFAULT_LOCALE;

        if (in_array($primaryLanguage, ['ar', 'en'], true)) {
            $locale = $primaryLanguage;
        }

        app()->setLocale($locale);

        $response = $next($request);
        $vary = $response->headers->get('Vary');
        $varyValues = $vary === null
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $vary))));

        if (! collect($varyValues)->contains(fn (string $value): bool => strcasecmp($value, 'Accept-Language') === 0)) {
            $varyValues[] = 'Accept-Language';
        }

        $response->headers->set('Content-Language', $locale);
        $response->headers->set('Vary', implode(', ', $varyValues));

        return $response;
    }
}
