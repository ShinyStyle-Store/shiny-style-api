<?php

namespace Tests\Feature\Api;

use App\Models\AdminMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AdminMaintenancePreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['password' => Hash::make('StrongPassword1!')]);
        AdminMembership::factory()->create(['user_id' => $this->owner->getKey(), 'role' => 'owner']);
    }

    public function test_disabled_cleanup_preview_is_hidden(): void
    {
        config(['app.allow_data_cleanup' => false]);

        $this->withToken($this->loginOwner())
            ->postJson('/api/v1/admin/maintenance/cleanup', [
                'targets' => ['archived_products'],
                'filters' => ['archived_products' => ['ids' => [42]]],
            ])->assertNotFound();
    }

    public function test_preview_rejects_an_ordinary_admin_without_the_cleanup_ability(): void
    {
        config(['app.allow_data_cleanup' => true]);
        $admin = User::factory()->create();
        AdminMembership::factory()->create(['user_id' => $admin->getKey(), 'role' => 'admin']);
        $adminToken = $admin->createToken('ordinary-admin', ['admin-access'])->plainTextToken;

        $this->withToken($adminToken)->postJson('/api/v1/admin/maintenance/cleanup', [
            'targets' => ['categories'], 'filters' => ['categories' => ['ids' => [1]]],
        ])->assertForbidden();
    }

    public function test_owner_login_persists_cleanup_ability_and_unsupported_orders_return_422(): void
    {
        config(['app.allow_data_cleanup' => true]);
        $ownerToken = $this->loginOwner();
        $ownerAccessToken = PersonalAccessToken::findToken($ownerToken);
        $this->assertNotNull($ownerAccessToken);
        $this->assertContains('maintenance.cleanup', $ownerAccessToken->abilities);
        $owner = User::query()->findOrFail($ownerAccessToken->tokenable_id);
        $this->assertSame('owner', $owner->adminMembership?->role);
        $this->assertTrue((bool) config('app.allow_data_cleanup'));

        $this->withToken($ownerToken)->postJson('/api/v1/admin/maintenance/cleanup', [
            'targets' => ['orders'], 'filters' => ['orders' => ['public_ids' => ['01J00000000000000000000000']]],
        ])->assertUnprocessable();
    }

    public function test_preview_rejects_broad_selection_and_performs_no_writes(): void
    {
        config(['app.allow_data_cleanup' => true]);
        $this->withToken($this->loginOwner())->postJson('/api/v1/admin/maintenance/cleanup', [
            'targets' => ['categories'], 'filters' => ['categories' => []],
        ])->assertUnprocessable();

        $beforeCategories = DB::table('categories')->count();
        $beforeProducts = DB::table('products')->count();
        $this->withToken($this->loginOwner())->postJson('/api/v1/admin/maintenance/cleanup', [
            'targets' => ['archived_products', 'categories'],
            'filters' => ['archived_products' => ['ids' => [999999]], 'categories' => ['ids' => [999999]]],
        ])->assertOk()
            ->assertJsonPath('counts.archived_products', 0)
            ->assertJsonPath('counts.categories', 0)
            ->assertJsonPath('blockers.0.code', 'product_not_archived_or_missing');

        $this->assertSame($beforeCategories, DB::table('categories')->count());
        $this->assertSame($beforeProducts, DB::table('products')->count());
    }

    public function test_execution_is_disabled_until_the_design_is_approved(): void
    {
        config(['app.allow_data_cleanup' => true]);

        $this->withToken($this->loginOwner())->postJson('/api/v1/admin/maintenance/cleanup', [
            'targets' => ['categories'],
            'filters' => ['categories' => ['ids' => [999999]]],
            'dry_run' => false,
        ])->assertUnprocessable();
    }

    private function loginOwner(): string
    {
        return $this->postJson('/api/v1/admin/auth/login', [
            'email' => $this->owner->email,
            'password' => 'StrongPassword1!',
        ])->assertOk()->json('data.access_token');
    }
}
