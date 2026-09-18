<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Models\AdminMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_returns_only_public_fields_for_an_admin(): void
    {
        $user = $this->admin(['phone' => null]);

        $this->withToken($this->token($user))->getJson('/api/v1/admin/profile')
            ->assertOk()
            ->assertJsonPath('data.public_id', $user->public_id)
            ->assertJsonPath('data.phone', null)
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.is_active')
            ->assertJsonMissingPath('data.admin_membership');
    }

    public function test_customer_token_and_suspended_membership_are_denied(): void
    {
        $user = $this->admin();
        $customer = $user->createToken('customer', ['customer-access'], now()->addHour())->plainTextToken;
        $admin = $this->token($user);

        $this->withToken($customer)->getJson('/api/v1/admin/profile')->assertForbidden();
        $this->forgetAuthGuards();

        $user->adminMembership->update(['status' => AdminMembershipStatus::Suspended]);
        $this->withToken($admin)->getJson('/api/v1/admin/profile')->assertForbidden();
    }

    public function test_profile_name_and_phone_updates_are_normalized_and_verified_state_is_safe(): void
    {
        $user = $this->admin([
            'name' => 'Old Name',
            'phone' => '01110007513',
            'phone_verified_at' => now(),
        ]);
        $token = $this->token($user);

        $this->withToken($token)->patchJson('/api/v1/admin/profile', [
            'name' => '  صاحب المتجر  ',
            'phone' => '+20 1110007514',
        ])->assertOk()->assertJsonPath('data.name', 'صاحب المتجر')
            ->assertJsonPath('data.phone', '01110007514');

        $this->assertNull($user->refresh()->phone_verified_at);
        $this->forgetAuthGuards();

        $user->update(['phone_verified_at' => now()]);
        $this->withToken($token)->patchJson('/api/v1/admin/profile', [
            'phone' => '01 1100 07514',
        ])->assertOk();
        $this->assertNotNull($user->refresh()->phone_verified_at);
        $this->forgetAuthGuards();

        $this->withToken($token)->patchJson('/api/v1/admin/profile', [
            'name' => 'Updated Name',
        ])->assertOk();
        $this->assertNotNull($user->refresh()->phone_verified_at);
        $this->forgetAuthGuards();

        $this->withToken($token)->patchJson('/api/v1/admin/profile', ['phone' => null])->assertOk();
        $this->assertNull($user->refresh()->phone);
        $this->assertNull($user->phone_verified_at);
    }

    public function test_profile_rejects_empty_names_unknown_fields_and_duplicate_phones(): void
    {
        $user = $this->admin(['phone' => '01110007513']);
        $other = User::factory()->create(['phone' => '01000000000']);
        $token = $this->token($user);

        $this->withToken($token)->patchJson('/api/v1/admin/profile', [])->assertUnprocessable();
        $this->forgetAuthGuards();
        $this->withToken($token)->patchJson('/api/v1/admin/profile', ['name' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->forgetAuthGuards();
        $this->withToken($token)->patchJson('/api/v1/admin/profile', ['email' => 'new@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->forgetAuthGuards();
        $this->withToken($token)->patchJson('/api/v1/admin/profile', ['phone' => $other->phone])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_password_change_preserves_current_token_and_customer_tokens_but_revokes_other_admin_tokens(): void
    {
        $user = $this->admin(['password' => Hash::make('Current@2026')]);
        $current = $user->createToken('current', ['admin-access'], now()->addHour());
        $other = $user->createToken('other', ['admin-access'], now()->addHour());
        $customer = $user->createToken('customer', ['customer-access'], now()->addHour());

        $this->withToken($current->plainTextToken)->putJson('/api/v1/admin/profile/password', [
            'current_password' => 'Current@2026',
            'password' => 'NewPass@2026',
            'password_confirmation' => 'NewPass@2026',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('NewPass@2026', $user->refresh()->password));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $customer->accessToken->id]);

        $this->forgetAuthGuards();
        $this->withToken($current->plainTextToken)->getJson('/api/v1/admin/profile')->assertOk();
        $this->forgetAuthGuards();
        $this->withToken($other->plainTextToken)->getJson('/api/v1/admin/profile')->assertUnauthorized();
        $this->forgetAuthGuards();
        $this->withToken($customer->plainTextToken)->getJson('/api/v1/admin/profile')->assertForbidden();
    }

    public function test_password_change_rejects_wrong_or_same_password_and_unknown_fields(): void
    {
        $user = $this->admin(['password' => Hash::make('Current@2026')]);
        $token = $this->token($user);

        $this->withToken($token)->putJson('/api/v1/admin/profile/password', [
            'current_password' => 'Wrong@2026',
            'password' => 'NewPass@2026',
            'password_confirmation' => 'NewPass@2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertTrue(Hash::check('Current@2026', $user->refresh()->password));
        $this->forgetAuthGuards();

        $this->withToken($token)->putJson('/api/v1/admin/profile/password', [
            'current_password' => 'Current@2026',
            'password' => 'Current@2026',
            'password_confirmation' => 'Current@2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('Current@2026', $user->refresh()->password));
        $this->forgetAuthGuards();

        $this->withToken($token)->putJson('/api/v1/admin/profile/password', [
            'current_password' => 'Current@2026',
            'password' => 'NewPass@2026',
            'password_confirmation' => 'NewPass@2026',
            'email' => 'not-allowed@example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertTrue(Hash::check('Current@2026', $user->refresh()->password));
    }

    private function admin(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'password' => Hash::make('Current@2026'),
        ], $attributes));
        AdminMembership::factory()->create(['user_id' => $user->id]);

        return $user->refresh();
    }

    private function token(User $user): string
    {
        return $user->createToken('admin', ['admin-access'], now()->addHour())->plainTextToken;
    }

    private function forgetAuthGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
