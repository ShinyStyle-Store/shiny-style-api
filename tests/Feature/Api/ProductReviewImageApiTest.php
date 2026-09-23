<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Models\AdminMembership;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductReviewImage;
use App\Models\SellableItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductReviewImageApiTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('review-images-test');
        config(['media.disk' => 'review-images-test']);

        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create([
            'user_id' => $admin->getKey(),
            'status' => AdminMembershipStatus::Active,
        ]);
        $this->adminToken = $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }

    public function test_admin_can_upload_list_reorder_delete_and_restore_review_images(): void
    {
        $product = $this->product();
        $first = $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$product->id.'/review-images', [
            'image' => UploadedFile::fake()->createWithContent('first.png', $this->imageBytes()),
            'status' => 'published',
            'alt_en' => 'First review',
        ])->assertCreated();
        $second = $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$product->id.'/review-images', [
            'image' => UploadedFile::fake()->createWithContent('second.png', $this->imageBytes()),
        ])->assertCreated();
        $third = $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$product->id.'/review-images', [
            'image' => UploadedFile::fake()->createWithContent('third.png', $this->imageBytes()),
        ])->assertCreated();

        $this->assertSame(0, $first->json('data.sortOrder'));
        $this->assertSame(1, $second->json('data.sortOrder'));
        $this->assertSame(2, $third->json('data.sortOrder'));
        $this->assertSame('customer_review_image', ProductReviewImage::first()->mediaAttachment->role);

        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/products/'.$product->id.'/review-images/reorder', [
            'items' => [
                ['id' => $third->json('data.id'), 'sort_order' => 0],
                ['id' => $second->json('data.id'), 'sort_order' => 1],
                ['id' => $first->json('data.id'), 'sort_order' => 2],
            ],
        ])->assertOk()->assertJsonPath('data.0.sortOrder', 0)
            ->assertJsonPath('data.1.sortOrder', 1)
            ->assertJsonPath('data.2.sortOrder', 2);

        $this->withToken($this->adminToken)->delete('/api/v1/admin/products/'.$product->id.'/review-images/'.$first->json('data.id'))
            ->assertNoContent();
        $this->assertSoftDeleted('product_review_images', ['id' => $first->json('data.id')]);

        $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$product->id.'/review-images/'.$first->json('data.id').'/restore')
            ->assertOk()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.sortOrder', 2);
    }

    public function test_reorder_rejects_an_incomplete_current_set(): void
    {
        $product = $this->product();
        $first = $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$product->id.'/review-images', [
            'image' => UploadedFile::fake()->createWithContent('first.png', $this->imageBytes()),
        ])->assertCreated();
        $second = $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$product->id.'/review-images', [
            'image' => UploadedFile::fake()->createWithContent('second.png', $this->imageBytes()),
        ])->assertCreated();

        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/products/'.$product->id.'/review-images/reorder', [
            'items' => [['id' => $first->json('data.id'), 'sort_order' => 0]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseHas('product_review_images', ['id' => $second->json('data.id'), 'sort_order' => 1]);
    }

    public function test_bulk_upload_is_ordered_and_public_endpoint_excludes_drafts(): void
    {
        $product = $this->product();
        $category = Category::create([
            'slug' => 'review-category-'.$this->uniqueId(),
            'name_ar' => 'تصنيف',
            'name_en' => 'Category',
            'status' => 'active',
            'sort_order' => 0,
        ]);
        $product->update(['status' => 'active', 'published_at' => now()]);
        $product->categories()->attach($category->id, ['is_primary' => true]);
        SellableItem::create([
            'product_id' => $product->id,
            'sku' => 'REVIEW-'.$this->uniqueId(),
            'price' => '10.00',
            'stock_quantity' => 1,
            'reserved_quantity' => 0,
            'status' => 'active',
        ]);
        $bulk = $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$product->id.'/review-images/bulk', [
            'images' => [
                UploadedFile::fake()->createWithContent('one.png', $this->imageBytes()),
                UploadedFile::fake()->createWithContent('two.png', $this->imageBytes()),
            ],
            'status' => 'published',
            'alt_en' => 'Customer feedback',
        ])->assertCreated()->assertJsonCount(2, 'data');
        $this->assertSame([0, 1], collect($bulk->json('data'))->pluck('sortOrder')->all());

        $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$product->id.'/review-images', [
            'image' => UploadedFile::fake()->createWithContent('draft.png', $this->imageBytes()),
        ])->assertCreated();

        $this->getJson('/api/v1/products/'.$product->slug.'/review-images')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_unknown_fields_and_invalid_files_are_rejected_without_records(): void
    {
        $product = $this->product();
        $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$product->id.'/review-images', [
            'image' => UploadedFile::fake()->create('malware.svg', 10, 'image/svg+xml'),
            'product_id' => $product->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('product_review_images', 0);
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_post_replaces_the_file_and_json_patch_updates_metadata_without_changing_order(): void
    {
        $product = $this->product();
        $created = $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$product->id.'/review-images', [
            'image' => UploadedFile::fake()->createWithContent('old.png', $this->imageBytes()),
        ])->assertCreated();
        $id = $created->json('data.id');

        $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$product->id.'/review-images/'.$id, [
            'image' => UploadedFile::fake()->createWithContent('new.png', $this->imageBytes()),
            'alt_en' => 'Replaced',
        ])->assertOk()->assertJsonPath('data.sortOrder', 0);

        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/products/'.$product->id.'/review-images/'.$id, [
            'status' => 'published',
            'alt_en' => 'Published',
        ])->assertOk()->assertJsonPath('data.sortOrder', 0);

        $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$product->id.'/review-images/'.$id, [
            'alt_en' => 'Invalid metadata POST',
        ])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_soft_deleted_rows_do_not_affect_append_order_and_empty_restore_starts_at_zero(): void
    {
        $product = $this->product();
        $created = $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$product->id.'/review-images', [
            'image' => UploadedFile::fake()->createWithContent('deleted.png', $this->imageBytes()),
        ])->assertCreated();
        $id = $created->json('data.id');
        $this->withToken($this->adminToken)->delete('/api/v1/admin/products/'.$product->id.'/review-images/'.$id)
            ->assertNoContent();

        $next = $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$product->id.'/review-images', [
            'image' => UploadedFile::fake()->createWithContent('next.png', $this->imageBytes()),
        ])->assertCreated();
        $this->assertSame(0, $next->json('data.sortOrder'));

        $emptyProduct = $this->product();
        $archived = $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$emptyProduct->id.'/review-images', [
            'image' => UploadedFile::fake()->createWithContent('restore.png', $this->imageBytes()),
        ])->assertCreated();
        $restoreId = $archived->json('data.id');
        $this->withToken($this->adminToken)->delete('/api/v1/admin/products/'.$emptyProduct->id.'/review-images/'.$restoreId)
            ->assertNoContent();
        $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$emptyProduct->id.'/review-images/'.$restoreId.'/restore')
            ->assertOk()->assertJsonPath('data.sortOrder', 0);
    }

    private function product(): Product
    {
        return Product::create([
            'slug' => 'review-'.$this->uniqueId(),
            'name_ar' => 'منتج',
            'name_en' => 'Product',
            'status' => 'draft',
            'is_featured' => false,
        ]);
    }

    private function uniqueId(): string
    {
        return Str::lower(Str::random(8));
    }

    private function imageBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAFElEQVR4nGOs0OBiYGBgYgADKAUADWAAsJHFWX0AAAAASUVORK5CYII=',
            true,
        );
    }
}
