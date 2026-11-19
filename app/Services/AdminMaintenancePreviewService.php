<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\SellableItem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AdminMaintenancePreviewService
{
    /** @return array<string, mixed> */
    public function preview(array $targets, array $filters): array
    {
        $products = in_array('archived_products', $targets, true)
            ? Product::onlyTrashed()->whereIn('id', $filters['archived_products']['ids'])->orderBy('id')->get()
            : collect();
        $categories = in_array('categories', $targets, true)
            ? Category::withTrashed()->whereIn('id', $filters['categories']['ids'])->orderBy('id')->get()
            : collect();
        $productCandidates = [];
        $categoryCandidates = [];
        $blockers = [];
        $dependencyCounts = [];
        $productsEligibleForRemoval = [];
        $seoSettingAssetIds = DB::table('seo_settings')
            ->whereIn('key', ['default_og_image_id', 'store_logo_id'])
            ->where('locale', '')
            ->pluck('value_json')
            ->map(static function (mixed $value): ?int {
                $decoded = is_string($value) ? json_decode($value, true) : $value;

                return is_numeric($decoded) ? (int) $decoded : null;
            })
            ->filter()
            ->all();

        foreach ($products as $product) {
            $productId = (int) $product->getKey();
            $variantIds = SellableItem::withTrashed()->where('product_id', $productId)->pluck('id');
            $orderItems = DB::table('order_items')->where(function (Builder $query) use ($productId, $variantIds): void {
                $query->where('product_id', $productId);
                if ($variantIds->isNotEmpty()) {
                    $query->orWhereIn('sellable_item_id', $variantIds);
                }
            })->count();
            $linkedOrderNumbers = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where(function (Builder $query) use ($productId, $variantIds): void {
                    $query->where('order_items.product_id', $productId);
                    if ($variantIds->isNotEmpty()) {
                        $query->orWhereIn('order_items.sellable_item_id', $variantIds);
                    }
                })
                ->distinct()
                ->orderBy('orders.order_number')
                ->pluck('orders.order_number')
                ->all();
            $variantRows = SellableItem::withTrashed()->where('product_id', $productId)->get(['id', 'stock_quantity', 'reserved_quantity', 'deleted_at']);
            $mediaOwnerIds = $variantRows->modelKeys();
            $mediaCount = DB::table('media_attachments')
                ->where(function (Builder $query) use ($product, $productId, $mediaOwnerIds): void {
                    $query->where(function (Builder $productOwner) use ($product, $productId): void {
                        $productOwner->where('mediable_type', $product->getMorphClass())->where('mediable_id', $productId);
                    });
                    if ($mediaOwnerIds !== []) {
                        $query->orWhere(function (Builder $variants) use ($mediaOwnerIds): void {
                            $variants->where('mediable_type', (new SellableItem)->getMorphClass())->whereIn('mediable_id', $mediaOwnerIds);
                        });
                    }
                })->count();
            $reviewImageIds = DB::table('product_review_images')->where('product_id', $productId)->pluck('id');
            $reviewMediaCount = $reviewImageIds->isEmpty() ? 0 : DB::table('media_attachments')
                ->where('mediable_type', 'product_review_image')->whereIn('mediable_id', $reviewImageIds)->count();
            $conversionCount = DB::table('product_conversions')->where('product_id', $productId)->count();
            $conversionSourceCount = $variantIds->isEmpty() ? 0 : DB::table('product_conversions')->whereIn('source_sellable_item_id', $variantIds)->count();
            $transferCount = $variantIds->isEmpty() ? 0 : DB::table('return_inventory_transfers')
                ->where(function (Builder $query) use ($variantIds): void {
                    $query->whereIn('source_sellable_item_id', $variantIds)->orWhereIn('target_sellable_item_id', $variantIds);
                })->count();
            $returnReceiptItemCount = $variantIds->isEmpty() ? 0 : DB::table('return_receipt_items')->whereIn('sellable_item_id', $variantIds)->count();
            $offerCount = DB::table('offer_product')->where('product_id', $productId)->count();
            $seoCount = DB::table('seo_records')->where('seoable_type', $product->getMorphClass())->where('seoable_id', $productId)->count();
            $seoRedirectCount = DB::table('seo_redirects')->where('entity_type', 'product')->where('entity_id', $productId)->count();
            $optionCount = DB::table('product_options')->where('product_id', $productId)->count();
            $optionValueCount = DB::table('product_option_values')
                ->whereIn('product_option_id', DB::table('product_options')->select('id')->where('product_id', $productId))
                ->count();
            $variantOptionAssignmentCount = $variantIds->isEmpty() ? 0 : DB::table('sellable_item_option_values')->whereIn('sellable_item_id', $variantIds)->count();
            $stockRiskCount = $variantRows->filter(fn (SellableItem $item): bool => (int) $item->stock_quantity !== 0 || (int) $item->reserved_quantity !== 0)->count();
            $sharedAssetCount = 0;
            $seoReferencedAssetCount = 0;
            if ($mediaCount + $reviewMediaCount > 0) {
                $ownerIdsByType = [(new Product)->getMorphClass() => [$productId], (new SellableItem)->getMorphClass() => $mediaOwnerIds, 'product_review_image' => $reviewImageIds->all()];
                $assetIds = DB::table('media_attachments')->where(function (Builder $query) use ($ownerIdsByType): void {
                    foreach ($ownerIdsByType as $ownerType => $ownerIds) {
                        if ($ownerIds !== []) {
                            $query->orWhere(function (Builder $owners) use ($ownerType, $ownerIds): void {
                                $owners->where('mediable_type', $ownerType)->whereIn('mediable_id', $ownerIds);
                            });
                        }
                    }
                })->pluck('media_asset_id')->unique();
                $sharedAssetCount = $assetIds->filter(fn (int $assetId): bool => DB::table('media_attachments')->where('media_asset_id', $assetId)->count() > DB::table('media_attachments')->where('media_asset_id', $assetId)->where(function (Builder $query) use ($ownerIdsByType): void {
                    foreach ($ownerIdsByType as $ownerType => $ownerIds) {
                        if ($ownerIds !== []) {
                            $query->orWhere(function (Builder $owners) use ($ownerType, $ownerIds): void {
                                $owners->where('mediable_type', $ownerType)->whereIn('mediable_id', $ownerIds);
                            });
                        }
                    }
                })->count() || DB::table('seo_records')->where('og_image_id', $assetId)->exists() || in_array($assetId, $seoSettingAssetIds, true))->count();
                $seoReferencedAssetCount = $assetIds->filter(fn (int $assetId): bool => DB::table('seo_records')->where('og_image_id', $assetId)->exists() || in_array($assetId, $seoSettingAssetIds, true))->count();
            }
            $counts = [
                'variants' => $variantRows->count(),
                'order_items' => $orderItems,
                'variant_inventory_records' => $stockRiskCount,
                'conversion_records' => $conversionCount + $conversionSourceCount,
                'return_inventory_transfers' => $transferCount,
                'return_receipt_items' => $returnReceiptItemCount,
                'product_options' => $optionCount,
                'product_option_values' => $optionValueCount,
                'variant_option_assignments' => $variantOptionAssignmentCount,
                'offer_links' => $offerCount,
                'seo_records' => $seoCount,
                'seo_redirects' => $seoRedirectCount,
                'media_attachments' => $mediaCount + $reviewMediaCount,
                'shared_media_assets' => $sharedAssetCount,
                'seo_referenced_media_assets' => $seoReferencedAssetCount,
                'review_images' => $reviewImageIds->count(),
            ];
            $dependencyCounts['products'][$productId] = $counts;
            $productCandidates[] = [
                'id' => $productId,
                'slug' => $product->slug,
                'name_ar' => $product->name_ar,
                'name_en' => $product->name_en,
                'archived_at' => $product->deleted_at?->toISOString(),
                'variant_ids' => $variantIds->map(static fn (mixed $id): int => (int) $id)->all(),
                'linked_order_numbers' => $linkedOrderNumbers,
                'dependency_counts' => $counts,
            ];

            if ($orderItems > 0) {
                $blockers[] = ['target' => 'archived_product', 'id' => $productId, 'code' => 'product_linked_to_order', 'count' => $orderItems];
            }
            if ($stockRiskCount > 0) {
                $blockers[] = ['target' => 'archived_product', 'id' => $productId, 'code' => 'variant_inventory_not_empty', 'count' => $stockRiskCount];
            }
            if ($conversionCount + $conversionSourceCount > 0) {
                $blockers[] = ['target' => 'archived_product', 'id' => $productId, 'code' => 'product_has_conversion_history', 'count' => $conversionCount + $conversionSourceCount];
            }
            if ($transferCount > 0) {
                $blockers[] = ['target' => 'archived_product', 'id' => $productId, 'code' => 'product_has_return_inventory_transfers', 'count' => $transferCount];
            }
            if ($returnReceiptItemCount > 0) {
                $blockers[] = ['target' => 'archived_product', 'id' => $productId, 'code' => 'variant_has_return_history', 'count' => $returnReceiptItemCount];
            }
            if ($seoRedirectCount > 0) {
                $blockers[] = ['target' => 'archived_product', 'id' => $productId, 'code' => 'product_has_seo_redirect_history', 'count' => $seoRedirectCount];
            }
            if ($orderItems === 0 && $stockRiskCount === 0 && $conversionCount + $conversionSourceCount === 0 && $transferCount === 0 && $returnReceiptItemCount === 0 && $seoRedirectCount === 0) {
                $productsEligibleForRemoval[] = $productId;
            }
        }

        foreach ($categories as $category) {
            $categoryId = (int) $category->getKey();
            $linkedProducts = DB::table('category_product')->where('category_id', $categoryId)->pluck('product_id');
            $remainingProductLinks = $linkedProducts->diff($productsEligibleForRemoval)->count();
            $childCategoryIds = DB::table('categories')->where('parent_id', $categoryId)->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $remainingProductIds = $linkedProducts->diff($productsEligibleForRemoval)->map(static fn (mixed $id): int => (int) $id)->values()->all();
            $children = count($childCategoryIds);
            $mediaCount = DB::table('media_attachments')->where('mediable_type', $category->getMorphClass())->where('mediable_id', $categoryId)->count();
            $seoCount = DB::table('seo_records')->where('seoable_type', $category->getMorphClass())->where('seoable_id', $categoryId)->count();
            $counts = ['product_links' => $linkedProducts->count(), 'product_links_remaining' => $remainingProductLinks, 'children' => $children, 'media_attachments' => $mediaCount, 'seo_records' => $seoCount];
            $dependencyCounts['categories'][$categoryId] = $counts;
            $categoryCandidates[] = [
                'id' => $categoryId,
                'slug' => $category->slug,
                'name_ar' => $category->name_ar,
                'name_en' => $category->name_en,
                'status' => $category->status,
                'archived_at' => $category->deleted_at?->toISOString(),
                'linked_product_ids' => $linkedProducts->map(static fn (mixed $id): int => (int) $id)->all(),
                'remaining_product_ids' => $remainingProductIds,
                'child_category_ids' => $childCategoryIds,
                'dependency_counts' => $counts,
            ];
            if ($remainingProductLinks > 0) {
                $blockers[] = ['target' => 'category', 'id' => $categoryId, 'code' => 'category_has_products_not_selected_for_cleanup', 'count' => $remainingProductLinks];
            }
            if ($children > 0) {
                $blockers[] = ['target' => 'category', 'id' => $categoryId, 'code' => 'category_has_child_categories', 'count' => $children];
            }
        }

        foreach ($targets as $target) {
            $requestedIds = $filters[$target]['ids'];
            $foundIds = match ($target) {
                'archived_products' => $products->modelKeys(),
                'categories' => $categories->modelKeys(),
            };
            foreach (array_diff($requestedIds, $foundIds) as $missingId) {
                $blockers[] = ['target' => $target, 'id' => $missingId, 'code' => $target === 'archived_products' ? 'product_not_archived_or_missing' : 'category_not_found'];
            }
        }

        return [
            'data' => ['archived_products' => $productCandidates, 'categories' => $categoryCandidates],
            'counts' => ['archived_products' => count($productCandidates), 'categories' => count($categoryCandidates)],
            'dependency_counts' => $dependencyCounts,
            'blockers' => $blockers,
            'fingerprint' => hash('sha256', json_encode([
                $targets,
                $filters,
                $productCandidates,
                $categoryCandidates,
                $dependencyCounts,
                $products->map->updated_at,
                $categories->map->updated_at,
            ], JSON_THROW_ON_ERROR)),
        ];
    }
}
