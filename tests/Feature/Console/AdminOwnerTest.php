<?php

namespace Tests\Feature\Console;

use App\Enums\AdminMembershipStatus;
use App\Models\AdminMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_owner_is_created_with_normalized_email_and_verified_identity(): void
    {
        $this->artisan('admin:create-owner')
            ->expectsQuestion('Name', 'Store Owner')
            ->expectsQuestion('Email', ' Owner@Example.COM ')
            ->expectsQuestion('Password', 'StrongPassword1!')
            ->expectsQuestion('Confirm password', 'StrongPassword1!')
            ->doesntExpectOutputToContain('StrongPassword1!')
            ->assertExitCode(0);

        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $membership = $user->adminMembership;

        $this->assertTrue(Hash::check('StrongPassword1!', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->is_active);
        $this->assertSame(AdminMembershipStatus::Active, $membership->status);
        $this->assertNotNull($membership->activated_at);
        $this->assertNull($membership->created_by);
    }

    public function test_owner_bootstrap_refuses_existing_memberships_and_weak_passwords(): void
    {
        AdminMembership::factory()->create();

        $this->artisan('admin:create-owner')
            ->expectsQuestion('Name', 'Another Owner')
            ->expectsQuestion('Email', 'another@example.com')
            ->expectsQuestion('Password', 'StrongPassword1!')
            ->expectsQuestion('Confirm password', 'StrongPassword1!')
            ->assertExitCode(1);

        $this->artisan('admin:create-owner')
            ->expectsQuestion('Name', 'Another Owner')
            ->expectsQuestion('Email', 'another@example.com')
            ->expectsQuestion('Password', 'weak')
            ->expectsQuestion('Confirm password', 'weak')
            ->assertExitCode(1);
    }
}
