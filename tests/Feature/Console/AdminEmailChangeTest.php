<?php

namespace Tests\Feature\Console;

use App\Enums\AdminMembershipStatus;
use App\Models\AdminMembership;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminEmailChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_is_changed_normalized_relationships_are_preserved_and_admin_tokens_are_revoked(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $membership = AdminMembership::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create(['user_id' => $user->id]);
        $adminToken = $user->createToken('admin', ['admin-access']);
        $customerToken = $user->createToken('customer', ['customer-access']);

        $this->artisan('admin:change-email')
            ->expectsQuestion('Current admin email', ' OLD@example.com ')
            ->expectsQuestion('New admin email', ' New@Example.COM ')
            ->expectsQuestion('Confirm new admin email', ' NEW@example.com ')
            ->assertExitCode(0);

        $user->refresh();
        $this->assertSame($user->getKey(), $membership->refresh()->user_id);
        $this->assertSame($user->getKey(), $order->refresh()->user_id);
        $this->assertSame('new@example.com', $user->email);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $adminToken->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $customerToken->accessToken->id]);
    }

    public function test_duplicate_email_is_rejected_without_changes_or_token_revocation(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        AdminMembership::factory()->create(['user_id' => $user->id]);
        $other = User::factory()->create(['email' => 'used@example.com']);
        $token = $user->createToken('admin', ['admin-access']);

        $this->artisan('admin:change-email')
            ->expectsQuestion('Current admin email', 'old@example.com')
            ->expectsQuestion('New admin email', ' USED@example.com ')
            ->expectsQuestion('Confirm new admin email', 'used@example.com')
            ->assertExitCode(1);

        $this->assertSame('old@example.com', $user->refresh()->email);
        $this->assertSame('used@example.com', $other->refresh()->email);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_unchanged_email_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        AdminMembership::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('admin', ['admin-access']);

        $this->artisan('admin:change-email')
            ->expectsQuestion('Current admin email', 'old@example.com')
            ->expectsQuestion('New admin email', ' OLD@example.com ')
            ->expectsQuestion('Confirm new admin email', 'old@example.com')
            ->assertExitCode(1);

        $this->assertSame('old@example.com', $user->refresh()->email);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_inactive_user_is_rejected(): void
    {
        $user = User::factory()->inactive()->create(['email' => 'inactive@example.com']);
        AdminMembership::factory()->create(['user_id' => $user->id]);

        $this->artisan('admin:change-email')
            ->expectsQuestion('Current admin email', 'inactive@example.com')
            ->expectsQuestion('New admin email', 'new@example.com')
            ->expectsQuestion('Confirm new admin email', 'new@example.com')
            ->assertExitCode(1);

        $this->assertSame('inactive@example.com', $user->refresh()->email);
    }

    public function test_inactive_membership_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'suspended@example.com']);
        AdminMembership::factory()->suspended()->create(['user_id' => $user->id]);

        $this->artisan('admin:change-email')
            ->expectsQuestion('Current admin email', 'suspended@example.com')
            ->expectsQuestion('New admin email', 'new@example.com')
            ->expectsQuestion('Confirm new admin email', 'new@example.com')
            ->assertExitCode(1);

        $this->assertSame('suspended@example.com', $user->refresh()->email);
        $this->assertSame(AdminMembershipStatus::Suspended, $user->adminMembership->status);
    }
}
