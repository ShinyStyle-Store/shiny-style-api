<?php

namespace Tests\Feature\Database;

use App\Enums\MediaRole;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Product;
use App\Models\SellableItem;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnifiedMediaDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_supported_morph_aliases_resolve_without_changing_existing_aliases(): void
    {
        $this->assertSame(Category::class, Relation::getMorphedModel('category'));
        $this->assertSame(Product::class, Relation::getMorphedModel('product'));
        $this->assertSame(SellableItem::class, Relation::getMorphedModel('sellable_item'));
    }

    public function test_product_image_and_video_relationships_are_role_and_kind_scoped_and_ordered(): void
    {
        $product = $this->product();
        $imageLate = $this->attach($product, MediaRole::PRODUCT_IMAGE, 'image', 4);
        $imageFirst = $this->attach($product, MediaRole::PRODUCT_IMAGE, 'image', 1, true);
        $imageTie = $this->attach($product, MediaRole::PRODUCT_IMAGE, 'image', 1);
        $video = $this->attach($product, MediaRole::PRODUCT_VIDEO, 'video', 0, true);
        $this->attach($this->category(), MediaRole::CATEGORY_COVER, 'image');

        $this->assertSame(
            [$imageFirst->id, $imageTie->id, $imageLate->id],
            $product->productImages()->pluck('id')->all(),
        );
        $this->assertSame([$video->id], $product->productVideos()->pluck('id')->all());
        $this->assertSame($imageFirst->id, $product->primaryProductImage()->value('id'));
        $product->load('productImages.mediaAsset', 'productVideos.mediaAsset');
        $this->assertSame('image', $product->productImages->first()->mediaAsset->media_type);
        $this->assertSame('video', $product->productVideos->first()->mediaAsset->media_type);
    }

    public function test_variant_images_are_scoped_separately_from_products_and_categories(): void
    {
        $product = $this->product();
        $variant = $this->variant($product);
        $otherVariant = $this->variant($product);
        $image = $this->attach($variant, MediaRole::VARIANT_IMAGE, 'image', 2, true);
        $this->attach($product, MediaRole::PRODUCT_IMAGE, 'image', 0, true);
        $this->attach($otherVariant, MediaRole::VARIANT_IMAGE, 'image', 0, true);
        $this->attach($variant, MediaRole::VARIANT_VIDEO, 'video');

        $this->assertSame([$image->id], $variant->variantImages()->pluck('id')->all());
        $this->assertSame($image->id, $variant->primaryVariantImage()->value('id'));
        $this->assertCount(1, $variant->variantVideos()->get());
        $this->assertCount(1, $product->productImages()->get());
    }

    public function test_primary_media_is_unique_per_owner_and_role_but_independent_between_owners(): void
    {
        $firstProduct = $this->product();
        $secondProduct = $this->product();
        $firstVariant = $this->variant($firstProduct);
        $secondVariant = $this->variant($secondProduct);
        $first = $this->attach($firstProduct, MediaRole::PRODUCT_IMAGE, 'image', 0, true);
        $this->attach($secondProduct, MediaRole::PRODUCT_IMAGE, 'image', 0, true);
        $this->attach($firstVariant, MediaRole::VARIANT_IMAGE, 'image', 0, true);
        $this->attach($secondVariant, MediaRole::VARIANT_IMAGE, 'image', 0, true);

        try {
            $this->attachment($first->mediaAsset, MediaRole::PRODUCT_IMAGE, 9, true, $firstProduct);
            $this->fail('The service accepted a duplicate primary image.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('This owner already has a primary attachment for the role.', $exception->getMessage());
        }

        $this->expectException(QueryException::class);
        DB::table('media_attachments')->insert([
            'media_asset_id' => $first->media_asset_id,
            'mediable_type' => 'product',
            'mediable_id' => $firstProduct->id,
            'role' => MediaRole::PRODUCT_IMAGE,
            'sort_order' => 9,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_invalid_owner_role_and_asset_kind_pairs_are_rejected(): void
    {
        $service = app(\App\Services\MediaService::class);
        $category = $this->category();
        $product = $this->product();
        $variant = $this->variant($product);
        $cases = [
            [$this->asset('image'), $category, MediaRole::PRODUCT_IMAGE],
            [$this->asset('image'), $category, MediaRole::VARIANT_IMAGE],
            [$this->asset('image'), $product, MediaRole::CATEGORY_COVER],
            [$this->asset('image'), $product, MediaRole::VARIANT_IMAGE],
            [$this->asset('image'), $variant, MediaRole::CATEGORY_COVER],
            [$this->asset('image'), $variant, MediaRole::PRODUCT_IMAGE],
            [$this->asset('video'), $product, MediaRole::PRODUCT_IMAGE],
        ];

        foreach ($cases as [$asset, $owner, $role]) {
            try {
                $service->attach($asset, $owner, $role);
                $this->fail('An incompatible owner, role, or media kind was accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertDatabaseCount('media_attachments', 0);
            }
        }

        try {
            $invalidAttachment = new MediaAttachment([
                'media_asset_id' => $this->asset('image')->id,
                'role' => MediaRole::CATEGORY_COVER,
            ]);
            $invalidAttachment->forceFill([
                'mediable_type' => 'product',
                'mediable_id' => $product->id,
            ])->save();
            $this->fail('Direct model creation bypassed owner-role validation.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('media_attachments', 0);
        }
    }

    public function test_asset_and_attachment_soft_deletion_excludes_assets_from_gallery(): void
    {
        $product = $this->product();
        $asset = $this->asset('image');
        $attachment = app(\App\Services\MediaService::class)->attach($asset, $product, MediaRole::PRODUCT_IMAGE);

        $asset->delete();
        $this->assertSame([], $product->productImages()->pluck('id')->all());

        $asset->restore();
        $attachment->delete();
        $this->assertSame([], $product->productImages()->pluck('id')->all());
    }

    public function test_asset_schema_can_record_video_without_fake_image_dimensions(): void
    {
        $asset = $this->asset('video');
        $this->assertNull($asset->width);
        $this->assertNull($asset->height);
        $this->assertSame('video/mp4', $asset->mime_type);
    }

    private function attach(Category|Product|SellableItem $owner, string $role, string $kind, int $order = 0, bool $primary = false): MediaAttachment
    {
        return app(\App\Services\MediaService::class)->attach(
            $this->asset($kind), $owner, $role,
            ['sort_order' => $order, 'is_primary' => $primary],
        );
    }

    private function attachment(MediaAsset $asset, string $role, int $order, bool $primary, Product $owner): MediaAttachment
    {
        return app(\App\Services\MediaService::class)->attach($asset, $owner, $role, [
            'sort_order' => $order, 'is_primary' => $primary,
        ]);
    }

    private function asset(string $kind): MediaAsset
    {
        return MediaAsset::query()->create([
            'disk' => 'media-test', 'path' => uniqid('media-', true),
            'media_type' => $kind, 'mime_type' => $kind === 'image' ? 'image/jpeg' : 'video/mp4',
            'extension' => $kind === 'image' ? 'jpg' : 'mp4', 'size_bytes' => 10,
            'width' => $kind === 'image' ? 10 : null,
            'height' => $kind === 'image' ? 10 : null,
        ]);
    }

    private function category(): Category
    {
        return Category::query()->create(['slug' => uniqid('category-'), 'name_ar' => 'تصنيف', 'name_en' => 'Category']);
    }

    private function product(): Product
    {
        return Product::query()->create(['slug' => uniqid('product-'), 'name_ar' => 'منتج', 'name_en' => 'Product']);
    }

    private function variant(Product $product): SellableItem
    {
        return SellableItem::query()->create([
            'product_id' => $product->id, 'sku' => uniqid('sku-'), 'price' => 10,
            'stock_quantity' => 1,
        ]);
    }
}
