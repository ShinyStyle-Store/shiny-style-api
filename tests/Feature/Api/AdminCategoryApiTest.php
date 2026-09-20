<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Models\AdminMembership;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->getKey()]);
        $this->adminToken = $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }

    public function test_all_category_routes_require_admin_bearer_authentication(): void
    {
        $categoryId = $this->category('unauthenticated-route')->getKey();
        $routes = [
            ['GET', '/api/v1/admin/categories', []],
            ['GET', '/api/v1/admin/categories/archived', []],
            ['GET', '/api/v1/admin/categories/'.$categoryId, []],
            ['POST', '/api/v1/admin/categories', $this->payload()],
            ['PATCH', '/api/v1/admin/categories/'.$categoryId, ['name_en' => 'Updated']],
            ['DELETE', '/api/v1/admin/categories/'.$categoryId, []],
        ];

        foreach ($routes as [$method, $uri, $body]) {
            $this->app['auth']->forgetGuards();
            $this->json($method, $uri, $body)->assertUnauthorized();
        }
    }

    public function test_customer_tokens_and_inactive_admin_memberships_are_rejected(): void
    {
        $categoryId = $this->category('forbidden-route')->getKey();
        $customer = User::factory()->create();
        $customerToken = $customer->createToken('customer', ['customer-access'])->plainTextToken;
        $customerRoutes = [
            ['GET', '/api/v1/admin/categories', []],
            ['GET', '/api/v1/admin/categories/archived', []],
            ['GET', '/api/v1/admin/categories/'.$categoryId, []],
            ['POST', '/api/v1/admin/categories', $this->payload()],
            ['PATCH', '/api/v1/admin/categories/'.$categoryId, ['name_en' => 'Updated']],
            ['DELETE', '/api/v1/admin/categories/'.$categoryId, []],
        ];
        foreach ($customerRoutes as [$method, $uri, $body]) {
            $this->app['auth']->forgetGuards();
            $this->json($method, $uri, $body, ['Authorization' => 'Bearer '.$customerToken])->assertForbidden();
        }

        foreach ([AdminMembershipStatus::Suspended, AdminMembershipStatus::Revoked] as $status) {
            $admin = User::factory()->create(['is_active' => true]);
            AdminMembership::factory()->create(['user_id' => $admin->getKey(), 'status' => $status]);
            $token = $admin->createToken('admin', ['admin-access'])->plainTextToken;
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/v1/admin/categories')->assertForbidden();
        }
    }

    public function test_admin_token_can_access_category_routes(): void
    {
        $category = $this->category('route-category');

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/categories')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/categories/'.$category->id)->assertOk();
        $this->app['auth']->forgetGuards();
        $created = $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories', $this->payload())
            ->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/categories/'.$category->id, ['name_en' => 'Changed'])
            ->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->deleteJson('/api/v1/admin/categories/'.$created->json('data.id'))
            ->assertNoContent();
    }

    public function test_list_is_flat_ordered_and_includes_bilingual_inactive_and_count_fields(): void
    {
        $later = $this->category('later', ['sort_order' => 2, 'status' => 'inactive']);
        $earlier = $this->category('earlier', ['sort_order' => 1]);
        $child = $this->category('child', ['parent_id' => $earlier->id, 'sort_order' => 1]);
        $product = Product::create([
            'slug' => 'admin-category-product-'.uniqid(),
            'name_ar' => 'منتج',
            'name_en' => 'Product',
            'status' => 'active',
        ]);
        $product->categories()->attach($earlier->id, ['is_primary' => true]);
        $childProduct = Product::create([
            'slug' => 'admin-child-product-'.uniqid(), 'name_ar' => 'Ù…Ù†ØªØ¬', 'name_en' => 'Child Product',
        ]);
        $childProduct->categories()->attach($child->id, ['is_primary' => true]);
        $deleted = $this->category('deleted');
        $deleted->delete();

        $response = $this->withToken($this->adminToken)->getJson('/api/v1/admin/categories')
            ->assertOk()
            ->assertJsonPath('data.0.id', $earlier->id)
            ->assertJsonPath('data.1.id', $child->id)
            ->assertJsonPath('data.2.id', $later->id)
            ->assertJsonPath('data.0.nameAr', 'تصنيف earlier')
            ->assertJsonPath('data.0.nameEn', 'Name earlier')
            ->assertJsonPath('data.0.depth', 1)
            ->assertJsonPath('data.0.parent', null)
            ->assertJsonPath('data.0.childrenCount', 1)
            ->assertJsonPath('data.0.productsCount', 1)
            ->assertJsonPath('data.0.hasChildren', true)
            ->assertJsonPath('data.0.isEffectivelyVisible', true)
            ->assertJsonPath('data.0.visibilityReason', null)
            ->assertJsonPath('data.1.parent.id', $earlier->id)
            ->assertJsonPath('data.1.parent.nameAr', $earlier->name_ar)
            ->assertJsonPath('data.1.parent.archived', false)
            ->assertJsonPath('data.1.productsCount', 1)
            ->assertJsonMissing(['id' => $deleted->id]);

        $this->assertArrayNotHasKey('children', $response->json('data.0'));
    }

    public function test_list_depths_do_not_issue_one_hierarchy_query_per_category(): void
    {
        foreach (range(1, 8) as $index) {
            $this->category('query-'.$index);
        }

        $categoryQueries = [];
        DB::listen(function ($query) use (&$categoryQueries): void {
            if (str_contains(strtolower($query->sql), 'categories')) {
                $categoryQueries[] = $query->sql;
            }
        });

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/categories')->assertOk();

        $this->assertCount(2, $categoryQueries);
    }

    public function test_admin_hierarchy_counts_do_not_depend_on_sibling_or_depth_sort_order(): void
    {
        $root = $this->category('unordered-root', ['sort_order' => 2]);
        $child = $this->category('unordered-child', ['parent_id' => $root->id, 'sort_order' => 3]);
        $grandchild = $this->category('unordered-grandchild', ['parent_id' => $child->id, 'sort_order' => 1]);

        $response = $this->withToken($this->adminToken)->getJson('/api/v1/admin/categories')->assertOk();
        $items = collect($response->json('data'))->keyBy('id');

        $this->assertSame(1, $items[$root->id]['childrenCount']);
        $this->assertTrue($items[$root->id]['hasChildren']);
        $this->assertSame(1, $items[$root->id]['depth']);
        $this->assertSame(1, $items[$child->id]['childrenCount']);
        $this->assertTrue($items[$child->id]['hasChildren']);
        $this->assertSame(2, $items[$child->id]['depth']);
        $this->assertSame(0, $items[$grandchild->id]['childrenCount']);
        $this->assertFalse($items[$grandchild->id]['hasChildren']);
        $this->assertSame(3, $items[$grandchild->id]['depth']);

        foreach ([$root, $child, $grandchild] as $category) {
            $this->assertTrue($items[$category->id]['isEffectivelyVisible']);
            $this->assertNull($items[$category->id]['visibilityReason']);
        }
    }

    public function test_detail_uses_numeric_ids_and_hides_missing_or_soft_deleted_categories(): void
    {
        $category = $this->category('detail');
        $child = $this->category('detail-child', ['parent_id' => $category->id]);

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/categories/'.$child->id)
            ->assertOk()
            ->assertJsonPath('data.id', $child->id)
            ->assertJsonPath('data.depth', 2);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/categories/999999')->assertNotFound();

        $category->delete();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/categories/'.$category->id)->assertNotFound();
    }

    public function test_create_accepts_root_child_and_grandchild_and_trims_strings(): void
    {
        $rootResponse = $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories', $this->payload([
            'slug' => '  ROOT-CATEGORY  ', 'name_ar' => '  اسم  ', 'name_en' => ' Root ',
        ]))->assertCreated()->assertJsonPath('data.slug', 'root-category')
            ->assertJsonPath('data.nameAr', 'اسم')->assertJsonPath('data.depth', 1);
        $rootId = $rootResponse->json('data.id');

        $this->app['auth']->forgetGuards();
        $childResponse = $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories', $this->payload([
            'slug' => 'root-child', 'parent_id' => $rootId,
        ]))->assertCreated()->assertJsonPath('data.depth', 2);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories', $this->payload([
            'slug' => 'root-grandchild', 'parent_id' => $childResponse->json('data.id'),
        ]))->assertCreated()->assertJsonPath('data.depth', 3);
    }

    public function test_create_rejects_fourth_level_and_invalid_or_soft_deleted_parents(): void
    {
        $root = $this->category('root');
        $child = $this->category('child', ['parent_id' => $root->id]);
        $grandchild = $this->category('grandchild', ['parent_id' => $child->id]);
        $deleted = $this->category('deleted-parent');
        $deleted->delete();

        foreach ([999999, $deleted->id, $grandchild->id] as $parentId) {
            $this->app['auth']->forgetGuards();
            $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories', $this->payload([
                'parent_id' => $parentId,
            ]))->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        }
    }

    public function test_create_rejects_duplicate_soft_deleted_slugs_and_unknown_fields(): void
    {
        $deleted = $this->category('reserved-slug', ['slug' => 'reserved-slug']);
        $deleted->delete();

        $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories', $this->payload([
            'slug' => 'reserved-slug',
        ]))->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories', $this->payload([
            'extra' => true,
        ]))->assertUnprocessable()->assertJsonValidationErrors('extra');
    }

    public function test_create_validates_names_status_and_sort_order(): void
    {
        $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories', $this->payload([
            'name_ar' => '   ', 'status' => 'hidden', 'sort_order' => -1,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['name_ar', 'status', 'sort_order']);
    }

    public function test_update_is_partial_and_explicit_null_moves_category_to_root(): void
    {
        $parent = $this->category('parent');
        $category = $this->category('child', ['parent_id' => $parent->id, 'description_en' => 'Keep me']);

        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/categories/'.$category->id, [
            'name_en' => 'Changed',
        ])->assertOk()->assertJsonPath('data.nameEn', 'Changed')
            ->assertJsonPath('data.descriptionEn', 'Keep me')->assertJsonPath('data.parentId', $parent->id);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/categories/'.$category->id, [
            'parent_id' => null, 'description_en' => null,
        ])->assertOk()->assertJsonPath('data.parentId', null)
            ->assertJsonPath('data.descriptionEn', null)->assertJsonPath('data.depth', 1);
    }

    public function test_update_rejects_empty_unknown_and_duplicate_slug_payloads(): void
    {
        $category = $this->category('update');
        $other = $this->category('other');

        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/categories/'.$category->id, [])
            ->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/categories/'.$category->id, ['extra' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('extra');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/categories/'.$category->id, ['slug' => $other->slug])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/categories/'.$category->id, ['slug' => strtoupper($category->slug)])
            ->assertOk()->assertJsonPath('data.slug', strtolower($category->slug));
    }

    public function test_update_rejects_self_direct_and_indirect_cycles_and_over_depth_subtree_moves(): void
    {
        $root = $this->category('root');
        $child = $this->category('child', ['parent_id' => $root->id]);
        $grandchild = $this->category('grandchild', ['parent_id' => $child->id]);

        foreach ([[$root, $root], [$root, $child], [$root, $grandchild]] as [$moving, $parent]) {
            $this->app['auth']->forgetGuards();
            $this->withToken($this->adminToken)->patchJson('/api/v1/admin/categories/'.$moving->id, [
                'parent_id' => $parent->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        }

        $otherRoot = $this->category('other-root');
        $moving = $this->category('moving', ['parent_id' => $otherRoot->id]);
        $movingChild = $this->category('moving-child', ['parent_id' => $moving->id]);
        $this->category('moving-grandchild', ['parent_id' => $movingChild->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/categories/'.$moving->id, [
            'parent_id' => $root->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_valid_subtree_move_succeeds_and_hierarchy_errors_are_validation_responses(): void
    {
        $target = $this->category('target');
        $this->category('target-child', ['parent_id' => $target->id]);
        $moving = $this->category('moving');
        $leaf = $this->category('leaf', ['parent_id' => $moving->id]);

        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/categories/'.$moving->id, [
            'parent_id' => $target->id,
        ])->assertOk()->assertJsonPath('data.parentId', $target->id)->assertJsonPath('data.depth', 2);
        $this->assertSame($target->id, $leaf->refresh()->parent->parent_id);
    }

    public function test_delete_soft_deletes_empty_category_and_returns_no_content(): void
    {
        $category = $this->category('empty');

        $this->withToken($this->adminToken)->deleteJson('/api/v1/admin/categories/'.$category->id)->assertNoContent();

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'status' => 'inactive']);
    }

    public function test_archived_list_is_flat_ordered_and_hides_archived_categories_from_normal_and_public_apis(): void
    {
        $active = $this->category('active-category', ['sort_order' => 1]);
        $inactive = $this->category('inactive-category', ['sort_order' => 2, 'status' => 'inactive']);
        $archived = $this->category('archived-category', ['sort_order' => 3]);
        $archived->delete();

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/categories/archived')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $archived->id)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.parent_id', null)
            ->assertJsonPath('data.0.is_effectively_visible', false)
            ->assertJsonPath('data.0.visibility_reason', 'self_archived')
            ->assertJsonPath('data.0.sort_order', 3)
            ->assertJsonPath('data.0.archived_at', $archived->deleted_at?->toISOString());

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/categories')
            ->assertOk()->assertJsonMissing(['id' => $archived->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson('/api/v1/categories')
            ->assertOk()->assertJsonMissing(['id' => $archived->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson('/api/v1/categories/'.$archived->slug)->assertNotFound();
        $this->assertDatabaseHas('categories', ['id' => $active->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('categories', ['id' => $inactive->id, 'deleted_at' => null]);
    }

    public function test_archived_list_parent_summary_identifies_an_archived_parent(): void
    {
        $parent = $this->category('archived-parent', ['sort_order' => 2]);
        $child = $this->category('archived-child', ['parent_id' => $parent->id, 'sort_order' => 1]);
        $activeDescendant = $this->category('active-descendant-of-archived', ['parent_id' => $child->id]);
        $parent->delete();
        $child->delete();

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/categories/archived')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.parent.id', $parent->id)
            ->assertJsonPath('data.0.parent.is_archived', true)
            ->assertJsonPath('data.0.depth', 2)
            ->assertJsonPath('data.0.children_count', 1)
            ->assertJsonMissing(['id' => $activeDescendant->id]);

        $this->getJson('/api/v1/categories')->assertOk()->assertJsonMissing(['slug' => $activeDescendant->slug]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/categories')->assertOk()
            ->assertJsonFragment([
                'id' => $activeDescendant->id,
                'isEffectivelyVisible' => false,
                'visibilityReason' => 'archived_ancestor',
            ]);
    }

    public function test_admin_visibility_reasons_cover_self_inactive_ancestors_and_invalid_cycles(): void
    {
        $visible = $this->category('visibility-visible');
        $inactive = $this->category('visibility-inactive', ['status' => 'inactive']);
        $behindInactive = $this->category('visibility-behind-inactive', ['parent_id' => $inactive->id]);
        $first = $this->category('visibility-cycle-first');
        $second = $this->category('visibility-cycle-second', ['parent_id' => $first->id]);
        DB::table('categories')->where('id', $first->id)->update(['parent_id' => $second->id]);

        $response = $this->withToken($this->adminToken)->getJson('/api/v1/admin/categories')->assertOk();
        $items = collect($response->json('data'))->keyBy('id');

        $this->assertTrue($items[$visible->id]['isEffectivelyVisible']);
        $this->assertNull($items[$visible->id]['visibilityReason']);
        $this->assertSame('self_inactive', $items[$inactive->id]['visibilityReason']);
        $this->assertSame(1, $items[$inactive->id]['childrenCount']);
        $this->assertTrue($items[$inactive->id]['hasChildren']);
        $this->assertSame('inactive_ancestor', $items[$behindInactive->id]['visibilityReason']);
        $this->assertFalse($items[$behindInactive->id]['isEffectivelyVisible']);
        $this->assertSame('invalid_hierarchy', $items[$first->id]['visibilityReason']);
        $this->assertNull($items[$first->id]['depth']);
    }

    public function test_admin_category_counts_and_archived_list_use_bounded_category_queries(): void
    {
        foreach (range(1, 8) as $index) {
            $category = $this->category('archived-query-'.$index);
            $category->delete();
        }

        $categoryQueries = [];
        DB::listen(function ($query) use (&$categoryQueries): void {
            if (str_contains(strtolower($query->sql), 'categories')) {
                $categoryQueries[] = $query->sql;
            }
        });

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/categories/archived')->assertOk();

        $this->assertCount(2, $categoryQueries);
    }

    public function test_archive_returns_not_found_for_missing_or_already_archived_categories(): void
    {
        $category = $this->category('already-archived');
        $category->delete();

        $this->withToken($this->adminToken)->deleteJson('/api/v1/admin/categories/999999')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->deleteJson('/api/v1/admin/categories/'.$category->id)->assertNotFound();
    }

    public function test_restore_root_preserves_fields_and_pivot_links_but_remains_inactive_until_activated(): void
    {
        $category = $this->category('restore-root', [
            'slug' => 'restore-root', 'name_ar' => 'Ø§Ù„ØªØµÙ†ÙŠÙ', 'name_en' => 'Restored',
            'sort_order' => 7,
        ]);
        $product = Product::create([
            'slug' => 'restore-product-'.uniqid(), 'name_ar' => 'Ù…Ù†ØªØ¬', 'name_en' => 'Product',
        ]);
        $product->categories()->attach($category->id, ['is_primary' => true]);
        $category->delete();

        $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories/'.$category->id.'/restore')
            ->assertOk()->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.slug', 'restore-root')->assertJsonPath('data.nameEn', 'Restored')
            ->assertJsonPath('data.parentId', null)->assertJsonPath('data.sortOrder', 7)
            ->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.depth', 1);
        $this->assertDatabaseHas('category_product', [
            'category_id' => $category->id, 'product_id' => $product->id, 'is_primary' => true,
        ]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson('/api/v1/categories')->assertOk()
            ->assertJsonMissing(['slug' => 'restore-root']);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/categories/'.$category->id, ['status' => 'active'])
            ->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson('/api/v1/categories/restore-root')->assertOk();
    }

    public function test_restore_accepts_an_inactive_parent_and_preserves_parent_and_sort_order(): void
    {
        $parent = $this->category('inactive-restore-parent', ['status' => 'inactive']);
        $child = $this->category('restore-child', ['parent_id' => $parent->id, 'sort_order' => 9]);
        $child->delete();

        $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories/'.$child->id.'/restore')
            ->assertOk()->assertJsonPath('data.parentId', $parent->id)
            ->assertJsonPath('data.sortOrder', 9)->assertJsonPath('data.status', 'inactive');
    }

    public function test_restore_conflicts_for_unavailable_parent_non_archived_and_missing_categories(): void
    {
        $parent = $this->category('restore-archived-parent');
        $child = $this->category('restore-parent-child', ['parent_id' => $parent->id]);
        $parent->delete();
        $child->delete();
        $ordinary = $this->category('not-archived');

        $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories/'.$child->id.'/restore')
            ->assertConflict()->assertJsonPath('code', 'category_parent_unavailable');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories/'.$ordinary->id.'/restore')
            ->assertConflict()->assertJsonPath('code', 'category_not_archived');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories/999999/restore')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories/'.$child->id.'/restore')
            ->assertConflict()->assertJsonPath('code', 'category_parent_unavailable');
    }

    public function test_restore_revalidates_depth_before_restoring(): void
    {
        $root = $this->category('restore-depth-root');
        $child = $this->category('restore-depth-child', ['parent_id' => $root->id]);
        $grandchild = $this->category('restore-depth-grandchild', ['parent_id' => $child->id]);
        $archived = $this->category('restore-depth-fourth', ['parent_id' => $grandchild->id]);
        $archived->delete();

        $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories/'.$archived->id.'/restore')
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_restore_is_repeatable_without_a_server_error(): void
    {
        $category = $this->category('repeat-restore');
        $category->delete();

        $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories/'.$category->id.'/restore')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories/'.$category->id.'/restore')
            ->assertConflict()->assertJsonPath('code', 'category_not_archived');
    }

    public function test_delete_conflicts_for_direct_children_and_preserves_relationships(): void
    {
        $parent = $this->category('parent');
        $child = $this->category('child', ['parent_id' => $parent->id]);

        $this->withToken($this->adminToken)->deleteJson('/api/v1/admin/categories/'.$parent->id)
            ->assertConflict()->assertJsonPath('code', 'category_has_children');

        $this->assertDatabaseHas('categories', ['id' => $parent->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('categories', ['id' => $child->id, 'parent_id' => $parent->id, 'deleted_at' => null]);
    }

    public function test_delete_conflicts_for_linked_products_including_soft_deleted_products(): void
    {
        $category = $this->category('linked');
        $product = Product::create([
            'slug' => 'linked-product-'.uniqid(),
            'name_ar' => 'منتج',
            'name_en' => 'Product',
        ]);
        $product->categories()->attach($category->id, ['is_primary' => true]);
        $product->delete();

        $this->withToken($this->adminToken)->deleteJson('/api/v1/admin/categories/'.$category->id)
            ->assertConflict()->assertJsonPath('code', 'category_has_products');

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('category_product', ['category_id' => $category->id, 'product_id' => $product->id]);
    }

    private function category(string $name, array $attributes = []): Category
    {
        return Category::create(array_merge([
            'slug' => Str::slug($name).'-'.uniqid(),
            'name_ar' => 'تصنيف '.$name,
            'name_en' => 'Name '.$name,
            'description_ar' => 'وصف '.$name,
            'description_en' => 'Description '.$name,
            'status' => 'active',
            'sort_order' => 0,
        ], $attributes));
    }

    private function payload(array $attributes = []): array
    {
        return array_merge([
            'parent_id' => null,
            'slug' => 'new-category-'.uniqid(),
            'name_ar' => 'تصنيف جديد',
            'name_en' => 'New category',
            'description_ar' => null,
            'description_en' => null,
            'status' => 'active',
            'sort_order' => 0,
        ], $attributes);
    }
}
