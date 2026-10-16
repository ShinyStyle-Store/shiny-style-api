<?php

namespace App\Http\Resources;

use App\Services\SeoResolverService;
use App\Services\SeoSettingsService;
use App\Services\SeoStructuredDataService;
use App\Services\StoreStructuredDataService;
use App\Support\ProductFeatures;
use App\Support\ProductSpecifications;
use Illuminate\Http\Request;

class ProductDetailResource extends ProductListResource
{
    public function toArray(Request $request): array
    {
        $productData = parent::toArray($request);
        $description = $this->localizedValue($this->description_ar, $this->description_en);
        $sellableItems = $this->publicSellableItems();
        $displayVariantId = $productData['displayVariantId'];
        $displaySellableItem = $sellableItems->first(
            fn ($item): bool => (string) $item->getKey() === $displayVariantId,
        );
        $seoImageAsset = $this->primaryImage($displaySellableItem)?->mediaAsset;
        $activeOptionValueIds = $sellableItems
            ->flatMap(fn ($sellableItem) => $sellableItem->optionValues->modelKeys())
            ->unique();
        $variantVideos = $sellableItems
            ->flatMap(fn ($sellableItem) => $sellableItem->variantVideos);
        $video = $this->orderedAttachments($variantVideos->concat($this->productVideos))->first();
        $settings = app(SeoSettingsService::class)->all();
        $seo = app(SeoResolverService::class)->resolve($this->resource, true, [
            ...$productData,
            'description' => $description,
            'imageAsset' => $seoImageAsset,
        ], $settings);
        $storeIdentity = app(StoreStructuredDataService::class)->identity($settings, app()->getLocale());
        $options = $this->options->map(fn ($option): array => [
            'id' => (string) $option->getKey(),
            'code' => $option->code,
            'name' => $this->localizedValue($option->name_ar, $option->name_en),
            'values' => $option->values
                ->filter(fn ($value): bool => $activeOptionValueIds->contains($value->getKey()))
                ->map(fn ($value): array => [
                    'id' => (string) $value->getKey(),
                    'code' => $value->code,
                    'label' => $this->localizedValue($value->value_ar, $value->value_en),
                    'metadata' => $value->metadata,
                ])->values()->all(),
        ])->filter(fn ($option): bool => $option['values'] !== [])->values()->all();
        $variantData = $sellableItems->map(fn ($item): array => [
            'id' => (string) $item->getKey(),
            'sku' => $item->sku,
            ...$this->pricePresentation($item),
            'inStock' => $item->availableQuantity() > 0,
            'images' => $item->variantImages
                ->map(fn ($attachment): ?string => $this->mediaUrl($attachment))
                ->filter(fn (?string $url): bool => $url !== null)
                ->values()
                ->all(),
            'optionValues' => (object) $item->optionValues->mapWithKeys(
                fn ($value): array => [$value->option->code => $value->code],
            )->all(),
        ])->values()->all();
        $gallery = $this->productImages
            ->map(fn ($attachment): ?string => $this->mediaUrl($attachment))
            ->filter(fn (?string $url): bool => $url !== null)
            ->values()
            ->all();
        $categoryBreadcrumbs = collect($this->seo_product_category_breadcrumbs ?? [])->map(fn ($category): array => [
            'id' => $category->getKey(),
            'name' => $this->localizedValue($category->name_ar, $category->name_en),
            'slug' => $category->slug,
        ])->values()->all();
        $structuredData = app(SeoStructuredDataService::class)->product(
            (string) $this->getKey(),
            $productData['name'],
            $description,
            $seo['canonicalPath'],
            $productData['image'],
            $gallery,
            $displayVariantId,
            $variantData,
            $options,
            $storeIdentity,
        );
        $structuredBreadcrumbs = app(SeoStructuredDataService::class)->breadcrumbs(
            $categoryBreadcrumbs,
            is_string($seo['canonicalPath']) ? [
                'entityType' => 'product',
                'id' => (string) $this->getKey(),
                'name' => $productData['name'],
                'path' => $seo['canonicalPath'],
            ] : null,
        );

        return [
            ...$productData,
            'description' => $description,
            'seo' => $seo,
            'structuredData' => [
                'product' => $structuredData,
                'breadcrumbs' => $structuredBreadcrumbs,
            ],
            'features' => ProductFeatures::localize($this->features),
            'specifications' => ProductSpecifications::localize($this->specifications),
            'gallery' => $gallery,
            'videoUrl' => $this->video_url ?: $this->mediaUrl($video),
            'options' => $options,
            'sellableItems' => $variantData,
            'defaultSellableItemId' => $displayVariantId,
        ];
    }
}
