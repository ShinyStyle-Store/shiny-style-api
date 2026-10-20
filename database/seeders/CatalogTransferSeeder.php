<?php

namespace Database\Seeders;

use Illuminate\Database\Connection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CatalogTransferSeeder extends Seeder
{
    private const TABLES = [
        'categories',
        'products',
        'category_product',
        'offers',
        'offer_product',
        'product_options',
        'product_option_values',
        'sellable_items',
        'sellable_item_option_values',
        'banners',
        'media_assets',
        'media_attachments',
        'store_contacts',
        'social_links',
    ];

    public function run(): void
    {
        $this->assertSourceConfigured();

        $source = DB::connection('catalog_source');
        $target = DB::connection();

        if ($this->sameDatabase($source, $target)) {
            throw new RuntimeException('The catalog source and destination must be different databases.');
        }

        $this->assertTargetIsEmpty($target);

        $activeCategories = $source->table('categories')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
        $categories = $this->categoriesWithVisibleAncestors($activeCategories);
        $categoryIds = array_column($categories, 'id');

        $categoryProductRows = $categoryIds === []
            ? []
            : $source->table('category_product')->whereIn('category_id', $categoryIds)->get()
                ->map(static fn (object $row): array => (array) $row)->all();
        $productIdsWithVisibleCategories = array_values(array_unique(array_column($categoryProductRows, 'product_id')));
        $productIdsWithPublicVariants = $source->table('sellable_items')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereNull('superseded_at')
            ->distinct()
            ->pluck('product_id')
            ->all();
        $publicVariantProductIdSet = array_fill_keys(array_map('strval', $productIdsWithPublicVariants), true);
        $productIds = array_values(array_filter(
            $productIdsWithVisibleCategories,
            static fn (int|string $productId): bool => isset($publicVariantProductIdSet[(string) $productId]),
        ));
        $products = $productIds === []
            ? []
            : $source->table('products')
                ->whereIn('id', $productIds)
                ->where('status', 'active')
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now()->utc()->toDateTimeString())
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        $productIds = array_column($products, 'id');
        $productIdSet = array_fill_keys(array_map('strval', $productIds), true);
        $categoryProductRows = array_values(array_filter(
            $categoryProductRows,
            static fn (array $row): bool => isset($productIdSet[(string) $row['product_id']]),
        ));

        $sellableItems = $productIds === []
            ? []
            : $source->table('sellable_items')
                ->whereIn('product_id', $productIds)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->whereNull('superseded_at')
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        $sellableItemIds = array_column($sellableItems, 'id');

        $activeOfferProductRows = $productIds === []
            ? []
            : $source->table('offer_product')->whereIn('product_id', $productIds)->get()
                ->map(static fn (object $row): array => (array) $row)->all();
        $activeOfferIds = $activeOfferProductRows === []
            ? []
            : $source->table('offers')
                ->whereIn('id', array_column($activeOfferProductRows, 'offer_id'))
                ->where('is_enabled', true)
                ->where('starts_at', '<=', now()->utc()->toDateTimeString())
                ->where('ends_at', '>', now()->utc()->toDateTimeString())
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        $activeOfferIds = array_column($activeOfferIds, 'id');
        $offers = $activeOfferIds === []
            ? []
            : $source->table('offers')->whereIn('id', $activeOfferIds)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all();
        $offerIdSet = array_fill_keys(array_map('strval', $activeOfferIds), true);
        $offerProductRows = array_values(array_filter(
            $activeOfferProductRows,
            static fn (array $row): bool => isset($offerIdSet[(string) $row['offer_id']]),
        ));

        $options = $productIds === []
            ? []
            : $source->table('product_options')
                ->whereIn('product_id', $productIds)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        $optionIds = array_column($options, 'id');
        $optionValues = $optionIds === []
            ? []
            : $source->table('product_option_values')
                ->whereIn('product_option_id', $optionIds)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        $optionValueIds = array_column($optionValues, 'id');
        $sellableItemOptionValues = $sellableItemIds === [] || $optionValueIds === []
            ? []
            : $source->table('sellable_item_option_values')
                ->whereIn('sellable_item_id', $sellableItemIds)
                ->whereIn('product_option_value_id', $optionValueIds)
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();

        $banners = $source->table('banners')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
        $bannerIds = array_column($banners, 'id');
        $storeContacts = $source->table('store_contacts')
            ->where('singleton_key', 'default')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
        $socialLinks = $source->table('social_links')
            ->where('is_enabled', true)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        $mediaAttachments = $this->mediaAttachments(
            $source,
            $categoryIds,
            $productIds,
            $sellableItemIds,
            $bannerIds,
        );
        $mediaAssetIds = array_values(array_unique(array_column($mediaAttachments, 'media_asset_id')));
        $mediaAssets = $mediaAssetIds === []
            ? []
            : $source->table('media_assets')
                ->whereIn('id', $mediaAssetIds)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get()
                ->map(static function (object $row): array {
                    $asset = (array) $row;
                    $asset['created_by'] = null;

                    return $asset;
                })
                ->all();
        $mediaAssetIds = array_column($mediaAssets, 'id');
        $mediaAttachments = array_values(array_filter(
            $mediaAttachments,
            static fn (array $row): bool => in_array($row['media_asset_id'], $mediaAssetIds, true),
        ));

        $target->transaction(function () use (
            $target,
            $categories,
            $products,
            $categoryProductRows,
            $offers,
            $offerProductRows,
            $options,
            $optionValues,
            $sellableItems,
            $sellableItemOptionValues,
            $banners,
            $mediaAssets,
            $mediaAttachments,
            $storeContacts,
            $socialLinks,
        ): void {
            $this->insertRows($target, 'categories', $this->orderCategories($categories));
            $this->insertRows($target, 'products', $products);
            $this->insertRows($target, 'category_product', $categoryProductRows);
            $this->insertRows($target, 'offers', $offers);
            $this->insertRows($target, 'offer_product', $offerProductRows);
            $this->insertRows($target, 'product_options', $options);
            $this->insertRows($target, 'product_option_values', $optionValues);
            $this->insertRows($target, 'sellable_items', $sellableItems);
            $this->insertRows($target, 'sellable_item_option_values', $sellableItemOptionValues);
            $this->insertRows($target, 'banners', $banners);
            $this->insertRows($target, 'media_assets', $mediaAssets);
            $this->insertRows($target, 'media_attachments', $mediaAttachments);
            $this->insertRows($target, 'store_contacts', $storeContacts);
            $this->insertRows($target, 'social_links', $socialLinks);

            $this->synchronizeSequences($target);
        });

        $this->command?->info(sprintf(
            'Transferred %d active categories, %d published products, %d active banners, and store contact/social details.',
            count($categories),
            count($products),
            count($banners),
        ));
    }

    /** @param list<array<string, mixed>> $categories
     *  @return list<array<string, mixed>>
     */
    private function categoriesWithVisibleAncestors(array $categories): array
    {
        $byId = [];
        foreach ($categories as $category) {
            $byId[(int) $category['id']] = $category;
        }

        return array_values(array_filter($categories, static function (array $category) use ($byId): bool {
            $currentId = (int) $category['id'];
            $visited = [];

            while (isset($byId[$currentId])) {
                if (isset($visited[$currentId])) {
                    return false;
                }

                $visited[$currentId] = true;
                $parentId = $byId[$currentId]['parent_id'];

                if ($parentId === null) {
                    return true;
                }

                $currentId = (int) $parentId;
            }

            return false;
        }));
    }

    /** @param list<array<string, mixed>> $categories
     *  @return list<array<string, mixed>>
     */
    private function orderCategories(array $categories): array
    {
        $byId = [];
        foreach ($categories as $category) {
            $byId[(int) $category['id']] = $category;
        }

        usort($categories, static function (array $left, array $right) use ($byId): int {
            $depth = static function (array $category) use ($byId): int {
                $depth = 0;
                $parentId = $category['parent_id'];
                while ($parentId !== null && isset($byId[(int) $parentId])) {
                    $depth++;
                    $parentId = $byId[(int) $parentId]['parent_id'];
                }

                return $depth;
            };

            return [$depth($left), (int) $left['id']] <=> [$depth($right), (int) $right['id']];
        });

        return $categories;
    }

    /** @return list<array<string, mixed>> */
    private function mediaAttachments(
        Connection $source,
        /** @var list<int|string> */
        array $categoryIds,
        /** @var list<int|string> */
        array $productIds,
        /** @var list<int|string> */
        array $sellableItemIds,
        /** @var list<int|string> */
        array $bannerIds,
    ): array {
        $owners = [
            'category' => $categoryIds,
            'product' => $productIds,
            'sellable_item' => $sellableItemIds,
            'banner' => $bannerIds,
        ];
        $query = $source->table('media_attachments');
        $hasOwners = false;

        foreach ($owners as $type => $ids) {
            if ($ids === []) {
                continue;
            }

            $method = $hasOwners ? 'orWhere' : 'where';
            $query->{$method}(function ($ownerQuery) use ($type, $ids): void {
                $ownerQuery->where('mediable_type', $type)->whereIn('mediable_id', $ids);
            });
            $hasOwners = true;
        }

        return ! $hasOwners
            ? []
            : $query->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    private function assertSourceConfigured(): void
    {
        $source = config('database.connections.catalog_source');
        if (! is_array($source)
            || (! is_string($source['url'] ?? null) || $source['url'] === '')
                && (! is_string($source['host'] ?? null) || $source['host'] === '')
        ) {
            throw new RuntimeException('Configure CATALOG_SOURCE_URL or CATALOG_SOURCE_HOST and the CATALOG_SOURCE_* credentials.');
        }
    }

    private function sameDatabase(Connection $source, Connection $target): bool
    {
        $sourceConfig = $source->getConfig();
        $targetConfig = $target->getConfig();
        $sourceEndpoint = $this->databaseEndpoint($sourceConfig);
        $targetEndpoint = $this->databaseEndpoint($targetConfig);

        return $sourceEndpoint !== null && $sourceEndpoint === $targetEndpoint;
    }

    /** @param array<string, mixed> $connectionConfig
     *  @return array{host: string, database: string}|null
     */
    private function databaseEndpoint(array $connectionConfig): ?array
    {
        $url = $connectionConfig['url'] ?? null;
        if (is_string($url) && $url !== '') {
            $parts = parse_url($url);
            if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
                return null;
            }

            return [
                'host' => strtolower($parts['host']),
                'database' => ltrim((string) ($parts['path'] ?? ''), '/'),
            ];
        }

        if (! is_string($connectionConfig['host'] ?? null)
            || ! is_string($connectionConfig['database'] ?? null)
        ) {
            return null;
        }

        return [
            'host' => strtolower($connectionConfig['host']),
            'database' => $connectionConfig['database'],
        ];
    }

    private function assertTargetIsEmpty(Connection $target): void
    {
        foreach (self::TABLES as $table) {
            if ($target->table($table)->exists()) {
                throw new RuntimeException("The destination table [{$table}] is not empty. Run this seeder only against an empty catalog database.");
            }
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertRows(Connection $target, string $table, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $target->table($table)->insert($chunk);
        }
    }

    private function synchronizeSequences(Connection $target): void
    {
        if ($target->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['categories', 'products', 'offers', 'product_options', 'product_option_values', 'sellable_items', 'banners', 'media_assets', 'media_attachments', 'store_contacts', 'social_links'] as $table) {
            $target->statement(
                "SELECT setval(pg_get_serial_sequence(?, 'id'), COALESCE(MAX(id), 1), MAX(id) IS NOT NULL) FROM \"{$table}\"",
                [$table],
            );
        }
    }
}
