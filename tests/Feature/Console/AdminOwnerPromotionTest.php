<?php

namespace Tests\Feature\Console;

use App\Models\AdminMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AdminOwnerPromotionTest extends TestCase
{
    use RefreshDatabase;

    public function test_promotion_requires_exact_account_confirmation_and_records_an_audit_log(): void
    {
        Log::spy();
        $user = User::factory()->create(['email' => 'selected@example.com']);
        $membership = AdminMembership::factory()->create(['user_id' => $user->getKey(), 'role' => 'admin']);

        $command = 'admin:promote-owner '.$user->public_id.' --reason="Initial owner designation"';
        $this->artisan($command)
            ->expectsQuestion("Type {$user->public_id} to confirm the exact account", $user->public_id)
            ->assertExitCode(0);

        $this->assertSame('owner', $membership->refresh()->role);
        Log::shouldHaveReceived('notice')->once()->withArgs(fn (string $message, array $context): bool =>
            $message === 'Admin membership role promotion reviewed by CLI'
            && $context['admin_membership_id'] === $membership->getKey()
            && $context['reason'] === 'Initial owner designation'
        );
    }

    public function test_promotion_does_not_change_the_account_when_confirmation_does_not_match(): void
    {
        $user = User::factory()->create(['email' => 'selected@example.com']);
        $membership = AdminMembership::factory()->create(['user_id' => $user->getKey(), 'role' => 'admin']);

        $command = 'admin:promote-owner '.$user->public_id.' --reason="Initial owner designation"';
        $this->artisan($command)
            ->expectsQuestion("Type {$user->public_id} to confirm the exact account", 'different-public-id')
            ->assertExitCode(1);

        $this->assertSame('admin', $membership->refresh()->role);
    }
}
