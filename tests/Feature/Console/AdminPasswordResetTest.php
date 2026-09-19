<?php

namespace Tests\Feature\Console;

use App\Models\AdminMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_changes_password_and_revokes_only_admin_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => Hash::make('OldPassword1!'),
        ]);
        AdminMembership::factory()->create(['user_id' => $user->id]);
        $adminToken = $user->createToken('admin', ['admin-access']);
        $customerToken = $user->createToken('customer', ['customer-access']);

        $this->artisan('admin:reset-password')
            ->expectsQuestion('Admin email', ' OWNER@example.com ')
            ->expectsQuestion('New password', 'NewPassword1!')
            ->expectsQuestion('Confirm new password', 'NewPassword1!')
            ->doesntExpectOutputToContain('NewPassword1!')
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('NewPassword1!', $user->refresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $adminToken->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $customerToken->accessToken->id]);
    }

    public function test_reset_does_not_reactivate_inactive_users_or_memberships(): void
    {
        $user = User::factory()->inactive()->create(['email' => 'inactive@example.com']);
        AdminMembership::factory()->suspended()->create(['user_id' => $user->id]);

        $this->artisan('admin:reset-password')
            ->expectsQuestion('Admin email', 'inactive@example.com')
            ->expectsQuestion('New password', 'NewPassword1!')
            ->expectsQuestion('Confirm new password', 'NewPassword1!')
            ->assertExitCode(1);

        $this->assertFalse($user->refresh()->is_active);
    }

    public function test_reset_rejects_weak_or_mismatched_passwords(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        AdminMembership::factory()->create(['user_id' => $user->id]);
        $originalPassword = $user->password;

        $this->artisan('admin:reset-password')
            ->expectsQuestion('Admin email', 'owner@example.com')
            ->expectsQuestion('New password', 'weak')
            ->expectsQuestion('Confirm new password', 'different')
            ->doesntExpectOutputToContain('weak')
            ->doesntExpectOutputToContain('different')
            ->assertExitCode(1);

        $this->assertSame($originalPassword, $user->refresh()->password);
    }

    public function test_reset_does_not_reactivate_revoked_memberships(): void
    {
        $user = User::factory()->create(['email' => 'revoked@example.com']);
        AdminMembership::factory()->revoked()->create(['user_id' => $user->id]);
        $originalPassword = $user->password;

        $this->artisan('admin:reset-password')
            ->expectsQuestion('Admin email', 'revoked@example.com')
            ->expectsQuestion('New password', 'NewPassword1!')
            ->expectsQuestion('Confirm new password', 'NewPassword1!')
            ->assertExitCode(1);

        $this->assertSame($originalPassword, $user->refresh()->password);
    }
}
