<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Models\AdminMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_admin_login_returns_scoped_expiring_token_and_updates_last_login(): void
    {
        $user = User::factory()->create([
            'name' => 'Owner', 'email' => 'owner@example.com',
            'password' => Hash::make('StrongPassword1!'),
        ]);
        $membership = AdminMembership::factory()->create(['user_id' => $user->id]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => ' OWNER@example.com ',
            'password' => 'StrongPassword1!',
            'device_name' => 'chrome-windows',
        ]);

        $response->assertOk()->assertJsonStructure([
            'data' => ['admin' => ['public_id', 'name', 'email'], 'access_token', 'token_type', 'expires_at'],
        ])->assertJsonPath('data.admin.email', 'owner@example.com')
            ->assertJsonPath('data.token_type', 'Bearer');

        $token = PersonalAccessToken::query()->latest('id')->firstOrFail();
        $this->assertSame(['admin-access'], $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertNotNull($membership->refresh()->last_login_at);
        $this->assertSame($token->expires_at->toISOString(), $response->json('data.expires_at'));
    }

    public function test_login_failures_are_generic_and_unknown_keys_are_validation_errors(): void
    {
        $payload = ['email' => 'unknown@example.com', 'password' => 'wrong-password'];
        $unknown = $this->postJson('/api/v1/admin/auth/login', $payload + ['extra' => true]);
        $unknown->assertUnprocessable()->assertJsonValidationErrors('extra');

        $user = User::factory()->create([
            'email' => 'owner@example.com', 'password' => Hash::make('StrongPassword1!'),
        ]);
        AdminMembership::factory()->create(['user_id' => $user->id]);
        $wrong = $this->postJson('/api/v1/admin/auth/login', ['email' => $user->email, 'password' => 'wrong']);
        $missing = $this->postJson('/api/v1/admin/auth/login', $payload);

        $wrong->assertUnauthorized()->assertJson($missing->json());
    }

    public function test_admin_middleware_rechecks_current_membership_and_distinguishes_non_admin_tokens(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $membership = AdminMembership::factory()->create(['user_id' => $user->id]);
        $expiresAt = now()->addHour();
        $adminToken = $user->createToken('admin', ['admin-access'], $expiresAt)->plainTextToken;
        $customerToken = $user->createToken('customer', ['customer-access'], $expiresAt)->plainTextToken;

        $this->withToken($customerToken)->getJson('/api/v1/admin/auth/me')->assertForbidden();
        $this->forgetAuthGuards();
        $this->withToken($adminToken)->getJson('/api/v1/admin/auth/me')->assertOk()
            ->assertJsonStructure(['data' => ['public_id', 'name', 'email']]);

        $membership->update(['status' => AdminMembershipStatus::Suspended]);
        $this->forgetAuthGuards();
        $this->withToken($adminToken)->getJson('/api/v1/admin/auth/me')->assertForbidden();
    }

    public function test_logout_current_and_logout_all_preserve_the_correct_tokens(): void
    {
        $user = User::factory()->create();
        AdminMembership::factory()->create(['user_id' => $user->id]);
        $expiresAt = now()->addHour();
        $firstAccessToken = $user->createToken('first', ['admin-access'], $expiresAt);
        $secondAccessToken = $user->createToken('second', ['admin-access'], $expiresAt);
        $customerAccessToken = $user->createToken('customer', ['customer-access'], $expiresAt);
        $first = $firstAccessToken->plainTextToken;
        $second = $secondAccessToken->plainTextToken;
        $customer = $customerAccessToken->plainTextToken;

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $firstAccessToken->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $secondAccessToken->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $customerAccessToken->accessToken->id]);

        $this->withToken($first)->postJson('/api/v1/admin/auth/logout')->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $firstAccessToken->accessToken->id]);
        $this->forgetAuthGuards();
        $this->withToken($first)->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
        $this->forgetAuthGuards();
        $this->withToken($second)->getJson('/api/v1/admin/auth/me')->assertOk();

        $this->forgetAuthGuards();
        $this->withToken($second)->postJson('/api/v1/admin/auth/logout-all')->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $secondAccessToken->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $customerAccessToken->accessToken->id]);
        $this->forgetAuthGuards();
        $this->withToken($second)->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
        $this->forgetAuthGuards();
        $this->withToken($customer)->getJson('/api/v1/admin/auth/me')->assertForbidden();
    }

    private function forgetAuthGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
