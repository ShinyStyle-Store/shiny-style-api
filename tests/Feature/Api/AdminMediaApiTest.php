<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Enums\MediaRole;
use App\Models\AdminMembership;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Product;
use App\Models\SellableItem;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RecreatesLegacyProductMediaTable;
use Tests\TestCase;

class AdminMediaApiTest extends TestCase
{
    use RecreatesLegacyProductMediaTable;
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recreateLegacyProductMediaTable();
        config(['media.disk' => 'media-test']);
        Storage::fake('media-test');

        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create([
            'user_id' => $admin->getKey(),
            'status' => AdminMembershipStatus::Active,
        ]);
        $this->adminToken = $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }

    public function test_every_product_and_sellable_item_media_route_requires_admin_bearer_authentication(): void
    {
        $product = $this->product();
        $variant = $this->variant($product);
        $routes = [
            ['GET', '/api/v1/admin/products/'.$product->id.'/media'],
            ['POST', '/api/v1/admin/products/'.$product->id.'/media'],
            ['PATCH', '/api/v1/admin/products/'.$product->id.'/media/1'],
            ['POST', '/api/v1/admin/products/'.$product->id.'/media/1/primary'],
            ['DELETE', '/api/v1/admin/products/'.$product->id.'/media/1'],
            ['GET', '/api/v1/admin/sellable-items/'.$variant->id.'/media'],
            ['POST', '/api/v1/admin/sellable-items/'.$variant->id.'/media'],
            ['PATCH', '/api/v1/admin/sellable-items/'.$variant->id.'/media/1'],
            ['POST', '/api/v1/admin/sellable-items/'.$variant->id.'/media/1/primary'],
            ['DELETE', '/api/v1/admin/sellable-items/'.$variant->id.'/media/1'],
        ];

        foreach ($routes as [$method, $uri]) {
            $this->app['auth']->forgetGuards();
            $this->json($method, $uri)->assertUnauthorized();
        }
    }

    public function test_customer_tokens_and_inactive_memberships_are_forbidden(): void
    {
        $product = $this->product();
        $customer = User::factory()->create();
        $customerToken = $customer->createToken('customer', ['customer-access'])->plainTextToken;
        $this->withToken($customerToken)->getJson('/api/v1/admin/products/'.$product->id.'/media')->assertForbidden();

        foreach ([AdminMembershipStatus::Pending, AdminMembershipStatus::Suspended, AdminMembershipStatus::Revoked] as $status) {
            $admin = User::factory()->create(['is_active' => true]);
            AdminMembership::factory()->create(['user_id' => $admin->getKey(), 'status' => $status]);
            $token = $admin->createToken('admin', ['admin-access'])->plainTextToken;
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/v1/admin/products/'.$product->id.'/media')->assertForbidden();
        }

        $inactiveUser = User::factory()->create(['is_active' => false]);
        AdminMembership::factory()->create([
            'user_id' => $inactiveUser->getKey(),
            'status' => AdminMembershipStatus::Active,
        ]);
        $this->app['auth']->forgetGuards();
        $this->withToken($inactiveUser->createToken('admin', ['admin-access'])->plainTextToken)
            ->getJson('/api/v1/admin/products/'.$product->id.'/media')->assertForbidden();
    }

    public function test_admin_lists_are_owner_scoped_ordered_and_do_not_expose_legacy_or_private_fields(): void
    {
        $product = $this->product();
        $otherProduct = $this->product();
        $first = $this->uploadFor($product, MediaRole::PRODUCT_IMAGE, 'image', 1, false);
        $tied = $this->uploadFor($product, MediaRole::PRODUCT_IMAGE, 'image', 1, true);
        $video = $this->uploadFor($product, MediaRole::PRODUCT_VIDEO, 'video', 0, false);
        $this->uploadFor($otherProduct, MediaRole::PRODUCT_IMAGE, 'image', 0, true);
        $softDeleted = $this->uploadFor($product, MediaRole::PRODUCT_IMAGE, 'image', 3, false);
        $softDeleted->mediaAsset->delete();

        $category = Category::query()->create([
            'slug' => 'admin-media-category-'.uniqid(), 'name_ar' => 'تصنيف', 'name_en' => 'Category',
        ]);
        $this->uploadFor($category, MediaRole::CATEGORY_COVER, 'image', 0, false);

        DB::table('product_media')->insert([
            'product_id' => $product->id,
            'provider' => 'cloudinary',
            'type' => 'image',
            'public_id' => 'legacy-media-'.uniqid(),
            'secure_url' => 'https://example.test/legacy.jpg',
            'sort_order' => 0,
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $legacyCount = DB::table('product_media')->count();

        $response = $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/products/'.$product->id.'/media')
            ->assertOk()
            ->assertJsonPath('data.0.id', $video->id)
            ->assertJsonPath('data.0.kind', 'video')
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.2.id', $tied->id)
            ->assertJsonPath('data.2.alt_ar', 'بديل عربي')
            ->assertJsonPath('data.2.alt_en', 'English alt')
            ->assertJsonPath('data.2.caption_ar', 'تعليق')
            ->assertJsonPath('data.2.is_primary', true)
            ->assertJsonMissing(['url' => 'https://example.test/legacy.jpg']);

        $this->assertSame([$video->id, $first->id, $tied->id], $response->json('data.*.id'));
        foreach (['disk', 'path', 'checksum', 'metadata', 'mediable_type', 'mediable_id', 'created_by'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $response->json('data.0'));
        }
        $this->assertSame($legacyCount, DB::table('product_media')->count());
    }

    public function test_sellable_item_listing_contains_only_its_variant_media(): void
    {
        $product = $this->product();
        $variant = $this->variant($product);
        $otherVariant = $this->variant($product);
        $image = $this->uploadFor($variant, MediaRole::VARIANT_IMAGE, 'image', 2, true);
        $this->uploadFor($variant, MediaRole::VARIANT_VIDEO, 'video', 1, false);
        $this->uploadFor($otherVariant, MediaRole::VARIANT_IMAGE, 'image', 0, true);
        $this->uploadFor($product, MediaRole::PRODUCT_IMAGE, 'image', 0, true);

        $response = $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/sellable-items/'.$variant->id.'/media')
            ->assertOk();

        $this->assertSame([MediaRole::VARIANT_VIDEO, MediaRole::VARIANT_IMAGE], $response->json('data.*.role'));
        $this->assertContains($image->id, $response->json('data.*.id'));
    }

    public function test_product_and_variant_uploads_select_roles_from_owner_and_inspected_kind(): void
    {
        $product = $this->product();
        $variant = $this->variant($product);
        $uploads = [
            ['/api/v1/admin/products/'.$product->id.'/media', $this->image('product.jpg'), 'image', MediaRole::PRODUCT_IMAGE],
            ['/api/v1/admin/products/'.$product->id.'/media', $this->video('product-video.bin'), 'video', MediaRole::PRODUCT_VIDEO],
            ['/api/v1/admin/sellable-items/'.$variant->id.'/media', $this->image('variant.jpg'), 'image', MediaRole::VARIANT_IMAGE],
            ['/api/v1/admin/sellable-items/'.$variant->id.'/media', $this->video('variant-video.bin'), 'video', MediaRole::VARIANT_VIDEO],
        ];

        foreach ($uploads as [$uri, $file, $kind, $role]) {
            $this->app['auth']->forgetGuards();
            $response = $this->withToken($this->adminToken)->post($uri, [
                'file' => $file,
                'kind' => $kind,
                'alt_ar' => ' عربي ',
                'alt_en' => ' English ',
                'caption_ar' => ' تعليق ',
                'sort_order' => 2,
            ])->assertCreated();

            $this->assertSame($role, $response->json('data.role'));
            $this->assertSame($kind, $response->json('data.kind'));
            $this->assertSame('عربي', $response->json('data.alt_ar'));
            $this->assertSame(2, $response->json('data.sort_order'));
            $this->assertStringNotContainsString('media-test', $response->json('data.url'));
        }
    }

    public function test_upload_rejects_fake_files_kind_mismatches_unknown_and_server_owned_fields(): void
    {
        $product = $this->product();
        $uri = '/api/v1/admin/products/'.$product->id.'/media';
        $invalid = [
            ['file' => UploadedFile::fake()->createWithContent('fake.jpg', 'not an image'), 'kind' => 'image'],
            ['file' => $this->image('unsupported-kind.jpg'), 'kind' => 'audio'],
            ['file' => $this->image('actually-image.jpg'), 'kind' => 'video'],
            ['file' => $this->video('actually-video.bin'), 'kind' => 'image'],
            ['file' => $this->image('unknown.jpg'), 'kind' => 'image', 'unexpected' => true],
            ['file' => $this->image('role.jpg'), 'kind' => 'image', 'role' => MediaRole::PRODUCT_VIDEO],
            ['file' => $this->image('disk.jpg'), 'kind' => 'image', 'disk' => 'public'],
            ['file' => $this->image('path.jpg'), 'kind' => 'image', 'path' => '../private'],
            ['file' => $this->image('mime.jpg'), 'kind' => 'image', 'mime_type' => 'image/png'],
        ];

        foreach ($invalid as $payload) {
            $this->app['auth']->forgetGuards();
            $this->withToken($this->adminToken)->post($uri, $payload)->assertUnprocessable();
        }

        $this->assertDatabaseCount('media_attachments', 0);
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media-test')->allFiles());
    }

    public function test_upload_rejects_files_over_the_configured_size_limit(): void
    {
        $product = $this->product();
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAFElEQVR4nGOs0OBiYGBgYgADKAUADWAAsJHFWX0AAAAASUVORK5CYII=', true);
        $limit = (int) config('media.images.max_image_size_bytes');
        $contents .= str_repeat("\0", $limit + 1 - strlen($contents));

        $this->withToken($this->adminToken)->post(
            '/api/v1/admin/products/'.$product->id.'/media',
            ['file' => UploadedFile::fake()->createWithContent('oversized.png', $contents), 'kind' => 'image'],
        )->assertUnprocessable();

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('media_attachments', 0);
        $this->assertSame([], Storage::disk('media-test')->allFiles());
    }

    public function test_upload_as_primary_preserves_and_switches_primaries_only_after_successful_validation(): void
    {
        $product = $this->product();
        $existing = $this->uploadFor($product, MediaRole::PRODUCT_IMAGE, 'image', 0, true);
        $video = $this->uploadFor($product, MediaRole::PRODUCT_VIDEO, 'video', 0, true);

        $response = $this->withToken($this->adminToken)->post(
            '/api/v1/admin/products/'.$product->id.'/media',
            ['file' => $this->image('new-primary.jpg'), 'kind' => 'image', 'is_primary' => 'true'],
        )->assertCreated()->assertJsonPath('data.is_primary', true);

        $this->assertFalse($existing->fresh()->is_primary);
        $this->assertTrue($video->fresh()->is_primary);
        $this->assertSame(1, $product->mediaAttachments()->where('role', MediaRole::PRODUCT_IMAGE)->where('is_primary', true)->count());
        $this->assertNotNull($response->json('data.id'));
    }

    public function test_metadata_patch_is_partial_and_rejects_empty_unknown_and_immutable_fields(): void
    {
        $product = $this->product();
        $attachment = $this->uploadFor($product, MediaRole::PRODUCT_IMAGE, 'image', 4, false);
        $otherAttachment = $this->uploadFor($product, MediaRole::PRODUCT_IMAGE, 'image', 4, false);
        $uri = '/api/v1/admin/products/'.$product->id.'/media/'.$attachment->id;

        $this->withToken($this->adminToken)->patchJson($uri, ['alt_en' => 'Updated'])
            ->assertOk()->assertJsonPath('data.alt_en', 'Updated')->assertJsonPath('data.alt_ar', 'بديل عربي');
        $this->assertSame('English alt', $otherAttachment->fresh()->alt_en);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson($uri, [])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson($uri, ['is_primary' => true])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson($uri, ['sort_order' => -1])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson($uri, ['alt_ar' => '  '])
            ->assertOk()->assertJsonPath('data.alt_ar', null);
    }

    public function test_primary_switching_is_owner_scoped_idempotent_and_independent_for_each_role(): void
    {
        $product = $this->product();
        $otherProduct = $this->product();
        $variant = $this->variant($product);
        $otherVariant = $this->variant($product);
        $first = $this->uploadFor($product, MediaRole::PRODUCT_IMAGE, 'image', 0, true);
        $second = $this->uploadFor($product, MediaRole::PRODUCT_IMAGE, 'image');
        $otherPrimary = $this->uploadFor($otherProduct, MediaRole::PRODUCT_IMAGE, 'image', 0, true);
        $variantPrimary = $this->uploadFor($variant, MediaRole::VARIANT_IMAGE, 'image', 0, true);
        $variantNext = $this->uploadFor($variant, MediaRole::VARIANT_IMAGE, 'image');
        $otherVariantPrimary = $this->uploadFor($otherVariant, MediaRole::VARIANT_IMAGE, 'image', 0, true);
        $videoPrimary = $this->uploadFor($product, MediaRole::PRODUCT_VIDEO, 'video', 0, true);

        $this->setPrimary($product, $first)->assertOk()->assertJsonPath('data.is_primary', true);
        $this->app['auth']->forgetGuards();
        $this->setPrimary($product, $second)->assertOk()->assertJsonPath('data.is_primary', true);
        $this->app['auth']->forgetGuards();
        $this->setPrimary($product, $second)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->setPrimary($product, $videoPrimary)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->setPrimary($variant, $variantNext)->assertOk()->assertJsonPath('data.is_primary', true);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertTrue($otherPrimary->fresh()->is_primary);
        $this->assertFalse($variantPrimary->fresh()->is_primary);
        $this->assertTrue($variantNext->fresh()->is_primary);
        $this->assertTrue($otherVariantPrimary->fresh()->is_primary);
        $this->assertTrue($videoPrimary->fresh()->is_primary);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson(
            '/api/v1/admin/products/'.$otherProduct->id.'/media/'.$second->id.'/primary',
        )->assertNotFound();
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_primary_switch_clears_stale_primary_whose_asset_was_soft_deleted(): void
    {
        $product = $this->product();
        $stalePrimary = $this->uploadFor($product, MediaRole::PRODUCT_IMAGE, 'image', 0, true);
        $stalePrimary->mediaAsset->delete();
        $replacement = $this->uploadFor($product, MediaRole::PRODUCT_IMAGE, 'image');

        $this->setPrimary($product, $replacement)
            ->assertOk()
            ->assertJsonPath('data.is_primary', true);

        $this->assertFalse($stalePrimary->fresh()->is_primary);
        $this->assertTrue($replacement->fresh()->is_primary);
        $this->assertSame(1, DB::table('media_attachments')
            ->where('mediable_type', $product->getMorphClass())
            ->where('mediable_id', $product->getKey())
            ->where('role', MediaRole::PRODUCT_IMAGE)
            ->where('is_primary', true)
            ->count());
    }

    public function test_delete_preserves_shared_assets_and_does_not_promote_a_new_primary(): void
    {
        $product = $this->product();
        $otherProduct = $this->product();
        $sharedAsset = $this->storedImage();
        $first = app(MediaService::class)->attach($sharedAsset, $product, MediaRole::PRODUCT_IMAGE, [
            'is_primary' => true,
        ]);
        $shared = app(MediaService::class)->attach($sharedAsset, $otherProduct, MediaRole::PRODUCT_IMAGE);
        $second = $this->uploadFor($product, MediaRole::PRODUCT_IMAGE, 'image');

        $this->withToken($this->adminToken)->deleteJson(
            '/api/v1/admin/products/'.$product->id.'/media/'.$first->id,
        )->assertNoContent();
        Storage::disk('media-test')->assertExists($sharedAsset->path);
        $this->assertDatabaseHas('media_assets', ['id' => $sharedAsset->id]);
        $this->assertFalse($second->fresh()->is_primary);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->deleteJson(
            '/api/v1/admin/products/'.$otherProduct->id.'/media/'.$shared->id,
        )->assertNoContent();
        Storage::disk('media-test')->assertMissing($sharedAsset->path);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->deleteJson(
            '/api/v1/admin/products/'.$product->id.'/media/'.$first->id,
        )->assertNotFound();
    }

    public function test_cross_owner_update_and_delete_return_not_found(): void
    {
        $firstProduct = $this->product();
        $secondProduct = $this->product();
        $variant = $this->variant($secondProduct);
        $productAttachment = $this->uploadFor($firstProduct, MediaRole::PRODUCT_IMAGE, 'image');
        $variantAttachment = $this->uploadFor($variant, MediaRole::VARIANT_IMAGE, 'image');

        $this->withToken($this->adminToken)->patchJson(
            '/api/v1/admin/products/'.$secondProduct->id.'/media/'.$productAttachment->id,
            ['alt_en' => 'IDOR'],
        )->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->deleteJson(
            '/api/v1/admin/sellable-items/'.$variant->id.'/media/'.$productAttachment->id,
        )->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->deleteJson(
            '/api/v1/admin/products/'.$secondProduct->id.'/media/'.$variantAttachment->id,
        )->assertNotFound();
    }

    private function setPrimary(Product|SellableItem $owner, MediaAttachment $attachment)
    {
        $prefix = $owner instanceof Product ? 'products' : 'sellable-items';

        return $this->withToken($this->adminToken)->postJson(
            '/api/v1/admin/'.$prefix.'/'.$owner->id.'/media/'.$attachment->id.'/primary',
        );
    }

    private function uploadFor(Category|Product|SellableItem $owner, string $role, string $kind, int $sortOrder = 0, bool $primary = false): MediaAttachment
    {
        $asset = $kind === 'image'
            ? app(MediaService::class)->uploadImage($this->image('asset.jpg'))
            : app(MediaService::class)->uploadVideo($this->video('asset.bin'));

        return app(MediaService::class)->attach($asset, $owner, $role, [
            'alt_ar' => 'بديل عربي',
            'alt_en' => 'English alt',
            'caption_ar' => 'تعليق',
            'sort_order' => $sortOrder,
            'is_primary' => $primary,
        ]);
    }

    private function storedImage(): MediaAsset
    {
        return app(MediaService::class)->uploadImage($this->image('shared.jpg'));
    }

    private function image(string $name): UploadedFile
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAFElEQVR4nGOs0OBiYGBgYgADKAUADWAAsJHFWX0AAAAASUVORK5CYII=', true);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function video(string $name): UploadedFile
    {
        $bytes = base64_decode('AAAAGGZ0eXBpc29tAAAAAGlzb20=', true);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function product(): Product
    {
        return Product::query()->create([
            'slug' => uniqid('admin-media-product-'), 'name_ar' => 'منتج', 'name_en' => 'Product',
        ]);
    }

    private function variant(Product $product): SellableItem
    {
        return SellableItem::query()->create([
            'product_id' => $product->id,
            'sku' => uniqid('admin-media-sku-'),
            'price' => 10,
            'stock_quantity' => 1,
        ]);
    }
}
