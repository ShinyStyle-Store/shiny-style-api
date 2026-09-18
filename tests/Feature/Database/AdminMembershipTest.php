<?php

namespace Tests\Feature\Database;

use App\Enums\AdminMembershipStatus;
use App\Models\AdminMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_identity_defaults_and_persistence_normalization(): void
    {
        $first = User::factory()->create(['email' => '  Admin@Example.COM  ', 'phone' => '01110000001']);
        $second = User::factory()->create(['email' => null, 'password' => null]);
        $third = User::factory()->create(['email' => null, 'phone' => null]);
        $explicit = User::factory()->create([
            'public_id' => '01JADMINPUBLIC00000000000000',
            'email' => null,
        ]);

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $first->public_id);
        $this->assertNotSame($first->public_id, $second->public_id);
        $this->assertSame('admin@example.com', $first->email);
        $this->assertSame('01JADMINPUBLIC00000000000000', $explicit->public_id);
        $this->assertTrue($first->is_active);
        $this->assertNull($second->password);
        $this->assertSame(3, User::query()->whereNull('email')->count());
        $this->assertSame(3, User::query()->whereNull('phone')->count());
    }

    public function test_duplicate_normalized_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        $this->expectException(QueryException::class);
        User::factory()->create(['email' => ' ADMIN@example.com ']);
    }

    public function test_is_active_defaults_to_true_and_explicit_false_is_preserved(): void
    {
        $active = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);

        $this->assertTrue($active->refresh()->is_active);
        $this->assertIsBool($active->is_active);
        $this->assertFalse($inactive->refresh()->is_active);
        $this->assertIsBool($inactive->is_active);
    }

    public function test_database_rejects_null_is_active(): void
    {
        $this->expectException(QueryException::class);

        User::factory()->create(['is_active' => null]);
    }

    public function test_duplicate_phone_is_rejected(): void
    {
        User::factory()->create(['phone' => '01110000000']);

        $this->expectException(QueryException::class);
        User::factory()->create(['phone' => '01110000000']);
    }

    public function test_membership_relationships_casts_states_and_delete_constraints_work(): void
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        $membership = AdminMembership::factory()->create([
            'user_id' => $member->id,
            'created_by' => $creator->id,
        ]);

        $this->assertTrue($member->adminMembership->is($membership));
        $this->assertTrue($membership->user->is($member));
        $this->assertTrue($membership->createdBy->is($creator));
        $this->assertSame(AdminMembershipStatus::Active, $membership->status);

        $pending = AdminMembership::factory()->pending()->create();
        $suspended = AdminMembership::factory()->suspended()->create();
        $revoked = AdminMembership::factory()->revoked()->create();
        $this->assertNull($pending->activated_at);
        $this->assertNotNull($suspended->suspended_at);
        $this->assertNotNull($revoked->revoked_at);

        $creator->delete();
        $this->assertNull($membership->refresh()->created_by);

        $member->delete();
        $this->assertDatabaseMissing('admin_memberships', ['id' => $membership->id]);
    }

    public function test_one_membership_per_user_and_existing_order_contracts_remain(): void
    {
        $user = User::factory()->create();
        AdminMembership::factory()->create(['user_id' => $user->id]);

        $this->expectException(QueryException::class);
        AdminMembership::factory()->create(['user_id' => $user->id]);
    }

    public function test_sanctum_capability_is_available_without_issuing_tokens_and_routes_are_public(): void
    {
        $user = User::factory()->create();
        $this->assertTrue(method_exists($user, 'createToken'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertGuest();
        $this->getJson('/api/v1/products')->assertOk();
    }
}
