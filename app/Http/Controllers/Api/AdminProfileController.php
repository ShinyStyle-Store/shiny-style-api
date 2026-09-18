<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAdminPasswordRequest;
use App\Http\Requests\UpdateAdminProfileRequest;
use App\Http\Resources\AdminProfileResource;
use App\Models\User;
use App\Services\AdminTokenService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AdminProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => (new AdminProfileResource($request->user()))->resolve($request),
        ]);
    }

    public function update(UpdateAdminProfileRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $user = DB::transaction(function () use ($request, $data): User {
                $user = User::query()->whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();

                if (array_key_exists('name', $data)) {
                    $user->name = $data['name'];
                }

                if (array_key_exists('phone', $data)) {
                    if ($user->phone !== $data['phone']) {
                        $user->phone = $data['phone'];
                        $user->phone_verified_at = null;
                    }
                }

                if ($user->isDirty()) {
                    $user->save();
                }

                return $user->refresh();
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw ValidationException::withMessages([
                    'phone' => 'The phone has already been taken.',
                ]);
            }

            throw $exception;
        }

        return response()->json([
            'data' => (new AdminProfileResource($user))->resolve($request),
        ]);
    }

    public function updatePassword(UpdateAdminPasswordRequest $request, AdminTokenService $tokens): Response
    {
        $data = $request->validated();
        $currentTokenId = $request->user()->currentAccessToken()?->getKey();

        DB::transaction(function () use ($request, $data, $tokens, $currentTokenId): void {
            $user = User::query()->whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();
            $membership = $user->adminMembership()->lockForUpdate()->first();

            if (! $user->is_active
                || $membership === null
                || $membership->status->value !== 'active'
                || $membership->activated_at === null) {
                throw ValidationException::withMessages([
                    'current_password' => 'The password cannot be changed for this account.',
                ]);
            }

            if ($user->password === null || ! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'The current password is incorrect.',
                ]);
            }

            if (Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages([
                    'password' => 'The new password must differ from the current password.',
                ]);
            }

            $user->forceFill(['password' => Hash::make($data['password'])])->save();
            $tokens->revokeAdminTokens($user, $currentTokenId === null ? null : (int) $currentTokenId);
        });

        return response()->noContent();
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique constraint');
    }
}
