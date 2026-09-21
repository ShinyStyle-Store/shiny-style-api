<?php

namespace Tests\Feature\Api;

use App\Enums\MediaRole;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\SellableItem;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RecreatesLegacyProductMediaTable;
use Tests\TestCase;

class ProductUnifiedMediaApiTest extends TestCase
{
    use RecreatesLegacyProductMediaTable;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recreateLegacyProductMediaTable();

        Storage::fake('product-media-test');
        config(['media.disk' => 'product-media-test']);
        $this->seed(ProductCatalogSeeder::class);
    }

    public function test_listing_and_featured_preserve_all_active_variant_primary_precedence_and_ignore_legacy_media(): void
    {
        $product = Product::where('slug', 'soft-sofa-throw-blanket')->firstOrFail();
        $variant = $product->sellableItems()->where('is_default', true)->firstOrFail();
        $otherVariant = $product->sellableItems()->where('is_default', false)->firstOrFail();
        $productPrimary = $this->attach($product, MediaRole::PRODUCT_IMAGE, 'product-primary.jpg', 1, true);
        $variantFallback = $this->attach($variant, MediaRole::VARIANT_IMAGE, 'variant-fallback.jpg', 1);
        $variantPrimary = $this->attach($variant, MediaRole::VARIANT_IMAGE, 'variant-primary.jpg', 9, true);
        $otherVariantPrimary = $this->attach($otherVariant, MediaRole::VARIANT_IMAGE, 'other-variant-primary.jpg', 0, true);
        ProductMedia::create([
            'product_id' => $product->getKey(),
            'provider' => 'cloudinary',
            'type' => 'image',
            'public_id' => 'legacy-product-image-'.uniqid(),
            'secure_url' => 'https://example.test/legacy.jpg',
        ]);

        $expected = Storage::disk('product-media-test')->url($otherVariantPrimary->mediaAsset->path);
        $listing = collect($this->getJson('/api/v1/products')->assertOk()->json('data'))
            ->firstWhere('slug', $product->slug);
        $featured = collect($this->getJson('/api/v1/products/featured')->assertOk()->json('data'))
            ->firstWhere('slug', $product->slug);

        $this->assertSame($expected, $listing['image']);
        $this->assertSame($expected, $featured['image']);
        $this->assertNotSame(Storage::disk('product-media-test')->url($productPrimary->mediaAsset->path), $listing['image']);
        $this->assertNotSame(Storage::disk('product-media-test')->url($variantPrimary->mediaAsset->path), $listing['image']);
        $this->assertNotSame('https://example.test/legacy.jpg', $listing['image']);
        $this->assertNotSame(Storage::disk('product-media-test')->url($variantFallback->mediaAsset->path), $listing['image']);
    }

    public function test_details_include_product_and_all_active_variant_media_in_legacy_order(): void
    {
        $product = Product::where('slug', 'soft-sofa-throw-blanket')->firstOrFail();
        $selected = $product->sellableItems()->where('is_default', true)->firstOrFail();
        $other = $product->sellableItems()->where('is_default', false)->firstOrFail();
        $selectedImage = $this->attach($selected, MediaRole::VARIANT_IMAGE, 'selected.jpg', 5, true);
        $productImage = $this->attach($product, MediaRole::PRODUCT_IMAGE, 'product-fallback.jpg', 1, true);
        $selectedVideo = $this->attach($selected, MediaRole::VARIANT_VIDEO, 'selected.mp4', 4);
        $this->attach($product, MediaRole::PRODUCT_VIDEO, 'product-video.mp4', 1, true);
        $otherImage = $this->attach($other, MediaRole::VARIANT_IMAGE, 'other-variant.jpg', 1, true);
        $otherVideo = $this->attach($other, MediaRole::VARIANT_VIDEO, 'other-variant.mp4', 0, true);

        $response = $this->getJson('/api/v1/products/'.$product->slug)->assertOk();
        $this->assertSame([
            Storage::disk('product-media-test')->url($productImage->mediaAsset->path),
            Storage::disk('product-media-test')->url($otherImage->mediaAsset->path),
            Storage::disk('product-media-test')->url($selectedImage->mediaAsset->path),
        ], $response->json('data.gallery'));
        $this->assertSame(Storage::disk('product-media-test')->url($otherVideo->mediaAsset->path), $response->json('data.videoUrl'));
        $this->assertNotSame(Storage::disk('product-media-test')->url($selectedVideo->mediaAsset->path), $response->json('data.videoUrl'));
    }

    public function test_ordered_product_fallback_excludes_deleted_assets_and_preserves_empty_and_video_only_shapes(): void
    {
        $product = Product::where('slug', 'espresso-coffee-machine')->firstOrFail();
        $first = $this->attach($product, MediaRole::PRODUCT_IMAGE, 'first.jpg', 3);
        $second = $this->attach($product, MediaRole::PRODUCT_IMAGE, 'second.jpg', 3);
        $deleted = $this->attach($product, MediaRole::PRODUCT_IMAGE, 'deleted.jpg', 0);
        $deleted->mediaAsset->delete();
        $videoAsset = MediaAsset::create([
            'disk' => 'product-media-test',
            'path' => 'video/wrong-image-kind.mp4',
            'original_name' => 'wrong-image-kind.mp4',
            'media_type' => 'video',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'size_bytes' => 10,
        ]);
        DB::table('media_attachments')->insert([
            'media_asset_id' => $videoAsset->getKey(),
            'mediable_type' => $product->getMorphClass(),
            'mediable_id' => $product->getKey(),
            'role' => MediaRole::PRODUCT_IMAGE,
            'sort_order' => 0,
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $image = $this->getJson('/api/v1/products/'.$product->slug)->assertOk();
        $this->assertSame(Storage::disk('product-media-test')->url($first->mediaAsset->path), $image->json('data.image'));
        $this->assertSame([
            Storage::disk('product-media-test')->url($first->mediaAsset->path),
            Storage::disk('product-media-test')->url($second->mediaAsset->path),
        ], $image->json('data.gallery'));
        $this->assertNull($image->json('data.videoUrl'));

        $emptyProduct = Product::where('slug', 'soft-sofa-throw-blanket')->firstOrFail();
        $emptyVariant = $emptyProduct->sellableItems()->where('is_default', true)->firstOrFail();
        $video = $this->attach($emptyProduct, MediaRole::PRODUCT_VIDEO, 'video-only.mp4', 0);
        $videoResponse = $this->getJson('/api/v1/products/'.$emptyProduct->slug)->assertOk();
        $this->assertSame([], $videoResponse->json('data.gallery'));
        $this->assertSame(Storage::disk('product-media-test')->url($video->mediaAsset->path), $videoResponse->json('data.videoUrl'));
        $this->assertSame($emptyVariant->getKey(), (int) $videoResponse->json('data.defaultSellableItemId'));
    }

    public function test_product_primary_image_beats_earlier_ordered_product_image(): void
    {
        $product = Product::where('slug', 'espresso-coffee-machine')->firstOrFail();
        $fallback = $this->attach($product, MediaRole::PRODUCT_IMAGE, 'earlier.jpg', 1);
        $primary = $this->attach($product, MediaRole::PRODUCT_IMAGE, 'primary.jpg', 8, true);

        $response = $this->getJson('/api/v1/products?per_page=100')->assertOk();
        $listing = collect($response->json('data'))->firstWhere('slug', $product->slug);

        $this->assertSame(Storage::disk('product-media-test')->url($primary->mediaAsset->path), $listing['image']);
        $this->assertNotSame(Storage::disk('product-media-test')->url($fallback->mediaAsset->path), $listing['image']);
    }

    public function test_inactive_variant_media_and_legacy_variant_media_are_not_returned(): void
    {
        $product = Product::where('slug', 'espresso-coffee-machine')->firstOrFail();
        $active = $product->sellableItems()->firstOrFail();
        $inactive = SellableItem::create([
            'product_id' => $product->getKey(),
            'sku' => 'INACTIVE-MEDIA-'.uniqid(),
            'price' => 10,
            'stock_quantity' => 0,
            'status' => 'inactive',
            'is_default' => false,
            'sort_order' => 10,
        ]);
        $inactiveImage = $this->attach($inactive, MediaRole::VARIANT_IMAGE, 'inactive.jpg', 0, true);
        $deletedVariant = SellableItem::create([
            'product_id' => $product->getKey(),
            'sku' => 'DELETED-MEDIA-'.uniqid(),
            'price' => 10,
            'stock_quantity' => 1,
            'status' => 'active',
            'is_default' => false,
            'sort_order' => 11,
        ]);
        $deletedVariantImage = $this->attach($deletedVariant, MediaRole::VARIANT_IMAGE, 'deleted-variant.jpg', 0, true);
        $inactiveVideo = $this->attach($inactive, MediaRole::VARIANT_VIDEO, 'inactive.mp4', 0, true);
        $deletedVariantVideo = $this->attach($deletedVariant, MediaRole::VARIANT_VIDEO, 'deleted-variant.mp4', 0, true);
        $deletedVariant->delete();
        ProductMedia::create([
            'product_id' => $product->getKey(),
            'sellable_item_id' => $inactive->getKey(),
            'provider' => 'cloudinary',
            'type' => 'image',
            'public_id' => 'legacy-inactive-'.uniqid(),
            'secure_url' => 'https://example.test/inactive-legacy.jpg',
        ]);

        $response = $this->getJson('/api/v1/products/'.$product->slug)->assertOk();
        $this->assertNotContains(Storage::disk('product-media-test')->url($inactiveImage->mediaAsset->path), $response->json('data.gallery'));
        $this->assertNotContains(Storage::disk('product-media-test')->url($deletedVariantImage->mediaAsset->path), $response->json('data.gallery'));
        $this->assertSame([], $response->json('data.gallery'));
        $this->assertNull($response->json('data.image'));
        $this->assertNull($response->json('data.videoUrl'));
        $this->assertNotSame(Storage::disk('product-media-test')->url($inactiveVideo->mediaAsset->path), $response->json('data.videoUrl'));
        $this->assertNotSame(Storage::disk('product-media-test')->url($deletedVariantVideo->mediaAsset->path), $response->json('data.videoUrl'));
        $this->assertNull($active->fresh()->variantImages()->first());
    }

    public function test_product_media_queries_are_bounded_for_listing(): void
    {
        $attachmentQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$attachmentQueries): void {
            if (str_contains(strtolower($query->sql), 'media_attachments')) {
                $attachmentQueries++;
            }
        });

        $this->getJson('/api/v1/products')->assertOk();

        $this->assertLessThanOrEqual(4, $attachmentQueries);
    }

    private function attach(Product|SellableItem $owner, string $role, string $filename, int $order, bool $primary = false): MediaAttachment
    {
        $type = str_contains($role, 'video') ? 'video' : 'image';
        $path = $type.'/'.$filename;
        Storage::disk('product-media-test')->put($path, 'test media');
        $asset = MediaAsset::create([
            'disk' => 'product-media-test',
            'path' => $path,
            'original_name' => $filename,
            'media_type' => $type,
            'mime_type' => $type === 'video' ? 'video/mp4' : 'image/jpeg',
            'extension' => pathinfo($filename, PATHINFO_EXTENSION),
            'size_bytes' => 10,
            'width' => $type === 'image' ? 1 : null,
            'height' => $type === 'image' ? 1 : null,
        ]);

        return $owner->mediaAttachments()->create([
            'media_asset_id' => $asset->getKey(),
            'role' => $role,
            'sort_order' => $order,
            'is_primary' => $primary,
            'alt_ar' => 'Arabic image',
            'alt_en' => 'English image',
        ]);
    }
}
