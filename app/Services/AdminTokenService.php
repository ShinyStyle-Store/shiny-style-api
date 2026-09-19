<?php

namespace App\Services;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class AdminTokenService
{
    /** @return list<PersonalAccessToken> */
    public function adminTokensFor(User $user): array
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->get()
            ->filter(fn (PersonalAccessToken $token): bool => is_array($token->abilities)
                && in_array('admin-access', $token->abilities, true))
            ->values()
            ->all();
    }

    public function revokeAdminTokens(User $user, ?int $exceptTokenId = null): void
    {
        foreach ($this->adminTokensFor($user) as $token) {
            if ($token->getKey() !== $exceptTokenId) {
                $token->delete();
            }
        }
    }
}
