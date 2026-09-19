<?php

namespace Database\Factories;

use App\Enums\AdminMembershipStatus;
use App\Models\AdminMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdminMembership> */
class AdminMembershipFactory extends Factory
{
    protected $model = AdminMembership::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => AdminMembershipStatus::Active,
            'created_by' => null,
            'activated_at' => now(),
            'suspended_at' => null,
            'revoked_at' => null,
            'last_login_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => AdminMembershipStatus::Pending,
            'activated_at' => null, 'suspended_at' => null, 'revoked_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => AdminMembershipStatus::Suspended,
            'activated_at' => now(), 'suspended_at' => now(), 'revoked_at' => null,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'status' => AdminMembershipStatus::Revoked,
            'activated_at' => now(), 'suspended_at' => null, 'revoked_at' => now(),
        ]);
    }
}
