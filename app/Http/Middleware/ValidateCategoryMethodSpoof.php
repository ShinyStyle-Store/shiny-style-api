<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Reject invalid method overrides before they can dispatch to another category action. */
final class ValidateCategoryMethodSpoof
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');
        $isCategoryItemPath = preg_match('#^api/v1/admin/categories/\d+$#', $path) === 1;
        $hasBodyMethodField = $request->request->has('_method');
        $hasMethodField = array_key_exists('_method', $request->all())
            || $request->query->has('_method');

        if (strtoupper($request->getRealMethod()) === 'POST' && $isCategoryItemPath && $hasMethodField) {
            $isValidPatchSpoof = $request->method() === 'PATCH'
                && str_contains(strtolower((string) $request->header('Content-Type')), 'multipart/form-data')
                && $hasBodyMethodField
                && strtoupper((string) $request->request->get('_method')) === 'PATCH';

            if (! $isValidPatchSpoof) {
                throw ValidationException::withMessages([
                    '_method' => 'Only multipart PATCH method spoofing is allowed for category updates.',
                ]);
            }
        }

        return $next($request);
    }
}
