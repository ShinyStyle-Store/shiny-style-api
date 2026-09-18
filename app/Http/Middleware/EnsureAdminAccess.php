<?php

namespace App\Http\Middleware;

use App\Support\AdminAuthMessages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($user === null || $token === null) {
            return response()->json(['message' => AdminAuthMessages::unauthorized()], 401);
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            return response()->json(['message' => AdminAuthMessages::unauthorized()], 401);
        }

        if (! is_array($token->abilities) || ! in_array('admin-access', $token->abilities, true)) {
            return response()->json(['message' => AdminAuthMessages::forbidden()], 403);
        }

        $membership = $user->adminMembership;
        if (! $user->is_active
            || $user->email_verified_at === null
            || $membership === null
            || $membership->status->value !== 'active'
            || $membership->activated_at === null) {
            return response()->json(['message' => AdminAuthMessages::forbidden()], 403);
        }

        return $next($request);
    }
}
