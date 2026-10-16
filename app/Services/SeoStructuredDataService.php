<?php

namespace App\Services;

final class SeoStructuredDataService
{
    /**
     * @param list<string> $galleryImages
     * @param list<array<string, mixed>> $variants
     * @param list<array<string, mixed>> $variationAttributes
     * @param array{organizationId: ?string, name: ?string, url: ?string, logo: ?string}|null $seller
     * @return array{
     *     productId: string,
     *     name: ?string,
     *     description: ?string,
     *     images: list<string>,
     *     canonicalPath: ?string,
     *     displayVariantId: ?string,
     *     offer: ?array{sellableItemId: string, sku: ?string, offerId: ?string, offerApplied: bool, price: ?float, priceCurrency: string, availability: string},
     *     variants: list<array{id: string, sku: ?string, offerId: ?string, offerApplied: bool, price: ?float, originalPrice: ?float, priceCurrency: string, availability: string, inStock: bool, images: list<string>, optionValues: array<string, string>}>,
     *     variationAttributes: list<array{code: string, name: ?string, values: list<array{code: string, label: ?string}>>},
     *     productGroup: ?array{productGroupId: string, variesBy: list<string>, variants: list<array{id: string, sku: ?string, optionValues: array<string, string>}>},
     *     seller: ?array{organizationId: ?string, name: string, url: ?string, logo: ?string}
     * }
     */
    public function product(
        string $productId,
        ?string $name,
        ?string $description,
        ?string $canonicalPath,
        ?string $primaryImage,
        array $galleryImages,
        ?string $displayVariantId,
        array $variants,
        array $variationAttributes,
        ?array $seller = null,
    ): array {
        $currency = (string) config('store.currency');
        $normalizedVariants = array_map(function (array $variant) use ($currency): array {
            $inStock = (bool) ($variant['inStock'] ?? false);

            return [
                'id' => (string) $variant['id'],
                'sku' => is_string($variant['sku'] ?? null) ? $variant['sku'] : null,
                'offerId' => is_string($variant['offerId'] ?? null) ? $variant['offerId'] : null,
                'offerApplied' => (bool) ($variant['offerApplied'] ?? false),
                'price' => is_numeric($variant['price'] ?? null) ? (float) $variant['price'] : null,
                'originalPrice' => is_numeric($variant['originalPrice'] ?? null)
                    ? (float) $variant['originalPrice']
                    : null,
                'priceCurrency' => $currency,
                'availability' => $inStock ? 'InStock' : 'OutOfStock',
                'inStock' => $inStock,
                'images' => $this->images(is_array($variant['images'] ?? null) ? $variant['images'] : []),
                'optionValues' => (array) ($variant['optionValues'] ?? []),
            ];
        }, $variants);

        $displayVariant = $displayVariantId === null
            ? null
            : collect($normalizedVariants)->firstWhere('id', $displayVariantId);
        $offer = $displayVariant === null ? null : [
            'sellableItemId' => $displayVariant['id'],
            'sku' => $displayVariant['sku'],
            'offerId' => $displayVariant['offerId'],
            'offerApplied' => $displayVariant['offerApplied'],
            'price' => $displayVariant['price'],
            'priceCurrency' => $currency,
            'availability' => $displayVariant['availability'],
        ];
        $normalizedAttributes = $this->variationAttributes($variationAttributes);
        $productGroup = $this->productGroup($productId, $normalizedVariants, $normalizedAttributes);

        return [
            'productId' => $productId,
            'name' => $name,
            'description' => $description,
            'images' => $this->images([
                ...($primaryImage === null ? [] : [$primaryImage]),
                ...$galleryImages,
                ...($displayVariant['images'] ?? []),
            ]),
            'canonicalPath' => $canonicalPath,
            'displayVariantId' => $displayVariantId,
            'offer' => $offer,
            'variants' => $normalizedVariants,
            'variationAttributes' => $normalizedAttributes,
            'productGroup' => $productGroup,
            'seller' => is_string($seller['name'] ?? null) && trim($seller['name']) !== ''
                ? $seller
                : null,
        ];
    }

    /**
     * @param array{organizationId: ?string, name: ?string, url: ?string, logo: ?string} $identity
     * @param array{supportPhone: ?string, supportWhatsapp: ?string, supportEmail: ?string, address: ?string, googleMapsUrl: ?string} $contact
     * @param list<string> $sameAs
     * @param array{returns: ?array{contentPageSlug: string, path: string, url: ?string}, shipping: ?array{contentPageSlug: string, path: string, url: ?string}} $policies
     * @return array{
     *     organization: array{organizationId: ?string, name: ?string, url: ?string, logo: ?string, contact: array{supportPhone: ?string, supportWhatsapp: ?string, supportEmail: ?string, address: ?string, googleMapsUrl: ?string}, sameAs: list<string>},
     *     website: array{websiteId: ?string, name: ?string, url: ?string, inLanguage: string, publisherId: ?string},
     *     currency: string,
     *     policies: array{returns: ?array{contentPageSlug: string, path: string, url: ?string}, shipping: ?array{contentPageSlug: string, path: string, url: ?string}}
     * }
     */
    public function storeProfile(
        array $identity,
        array $contact,
        array $sameAs,
        string $currency,
        string $locale,
        array $policies,
    ): array {
        $websiteId = $identity['url'] === null ? null : $identity['url'].'/#website';

        return [
            'organization' => [
                ...$identity,
                'contact' => $contact,
                'sameAs' => $sameAs,
            ],
            'website' => [
                'websiteId' => $websiteId,
                'name' => $identity['name'],
                'url' => $identity['url'],
                'inLanguage' => $locale,
                'publisherId' => $identity['organizationId'],
            ],
            'currency' => $currency,
            'policies' => $policies,
        ];
    }

    /**
     * @param list<array{id: int|string, name: ?string, slug: string}> $categories
     * @param array{entityType: string, id: string, name: ?string, path: string}|null $terminal
     * @return list<array{position: int, entityType: string, id: string, name: ?string, path: string}>
     */
    public function breadcrumbs(array $categories, ?array $terminal = null): array
    {
        $items = [];
        foreach ($categories as $category) {
            $items[] = [
                'position' => count($items) + 1,
                'entityType' => 'category',
                'id' => (string) $category['id'],
                'name' => $category['name'],
                'path' => '/category/'.$category['slug'],
            ];
        }

        if ($terminal !== null) {
            $items[] = [
                'position' => count($items) + 1,
                ...$terminal,
            ];
        }

        return $items;
    }

    /** @param list<string> $images @return list<string> */
    private function images(array $images): array
    {
        return array_values(array_unique(array_filter(
            $images,
            static fn (mixed $image): bool => is_string($image) && trim($image) !== '',
        )));
    }

    /**
     * @param list<array<string, mixed>> $attributes
     * @return list<array{code: string, name: ?string, values: list<array{code: string, label: ?string}>}>
     */
    private function variationAttributes(array $attributes): array
    {
        return array_values(array_map(static fn (array $attribute): array => [
            'code' => (string) $attribute['code'],
            'name' => is_string($attribute['name'] ?? null) ? $attribute['name'] : null,
            'values' => array_values(array_map(static fn (array $value): array => [
                'code' => (string) $value['code'],
                'label' => is_string($value['label'] ?? null) ? $value['label'] : null,
            ], $attribute['values'] ?? [])),
        ], $attributes));
    }

    /**
     * @param list<array<string, mixed>> $variants
     * @param list<array{code: string, name: ?string, values: list<array{code: string, label: ?string}>}> $attributes
     * @return array{productGroupId: string, variesBy: list<string>, variants: list<array{id: string, sku: ?string, optionValues: array<string, string>}>}|null
     */
    private function productGroup(string $productId, array $variants, array $attributes): ?array
    {
        $variesBy = array_values(array_unique(array_column($attributes, 'code')));
        if (count($variants) < 2 || $variesBy === []) {
            return null;
        }

        foreach ($variants as $variant) {
            foreach ($variesBy as $optionCode) {
                $value = $variant['optionValues'][$optionCode] ?? null;
                if (! is_string($value) || trim($value) === '') {
                    return null;
                }
            }
        }

        return [
            'productGroupId' => $productId,
            'variesBy' => $variesBy,
            'variants' => array_map(static fn (array $variant): array => [
                'id' => $variant['id'],
                'sku' => $variant['sku'],
                'optionValues' => $variant['optionValues'],
            ], $variants),
        ];
    }
}
