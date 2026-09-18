<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminLoginRequest;
use App\Http\Resources\AdminIdentityResource;
use App\Models\User;
use App\Services\AdminTokenService;
use App\Support\AdminAuthMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthController extends Controller
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';

    public function login(AdminLoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $email = $data['email'];
        $password = $data['password'];
        $deviceName = $data['device_name'] ?? 'admin-api';
        $user = User::query()->where('email', $email)->first();
        $passwordHash = $user?->password;
        $passwordValid = Hash::check($password, is_string($passwordHash) && $passwordHash !== '' ? $passwordHash : self::DUMMY_PASSWORD_HASH);

        if (! $passwordValid || $user === null) {
            return $this->invalidCredentials();
        }

        $result = DB::transaction(function () use ($user, $password, $deviceName): ?array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            $membership = $lockedUser?->adminMembership()->lockForUpdate()->first();

            if ($lockedUser === null
                || $lockedUser->password === null
                || ! Hash::check($password, $lockedUser->password)
                || ! $lockedUser->is_active
                || $lockedUser->email_verified_at === null
                || $membership === null
                || $membership->status->value !== 'active'
                || $membership->activated_at === null) {
                return null;
            }

            $expiresAt = now()->addMinutes((int) config('admin.token_expiration_minutes', 720));
            $token = $lockedUser->createToken($deviceName, ['admin-access'], $expiresAt);
            $membership->forceFill(['last_login_at' => now()])->save();

            return [
                'user' => $lockedUser,
                'plain_text_token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at,
            ];
        });

        if ($result === null) {
            return $this->invalidCredentials();
        }

        return response()->json([
            'data' => [
                'admin' => (new AdminIdentityResource($result['user']))->resolve($request),
                'access_token' => $result['plain_text_token'],
                'token_type' => 'Bearer',
                'expires_at' => $result['expires_at']?->toISOString(),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => (new AdminIdentityResource($request->user()))->resolve($request),
        ]);
    }

    public function logout(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return response()->json(['message' => AdminAuthMessages::unauthorized()], 401);
        }

        $tokenId = $token->getKey();
        $token->delete();

        if (PersonalAccessToken::query()->whereKey($tokenId)->exists()) {
            throw new \RuntimeException('The current access token could not be revoked.');
        }

        return response()->noContent();
    }

    public function logoutAll(Request $request, AdminTokenService $tokens): Response
    {
        $tokens->revokeAdminTokens($request->user());

        return response()->noContent();
    }

    private function invalidCredentials(): JsonResponse
    {
        return response()->json(['message' => AdminAuthMessages::invalidCredentials()], 401);
    }
}
