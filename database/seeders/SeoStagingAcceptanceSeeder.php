<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\SeoSetting;
use App\Models\SellableItem;
use App\Services\CategoryHierarchyService;
use App\Services\SeoPublicImageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class SeoStagingAcceptanceSeeder extends Seeder
{
    private const PREFIX = 'SEO-STAGING-';

    public function run(): void
    {
        $this->seed();
    }

    /** @return array{products_created: int, products_eligible_ar: int, products_eligible_en: int} */
    public function seed(bool $withPagination = false): array
    {
        $this->assertStagingEnvironment();
        $configuration = $this->validatedConfiguration();

        return DB::transaction(function () use ($withPagination, $configuration): array {
            $category = $this->ensureCategory();
            $initialProductIds = Product::query()->where('slug', 'like', 'seo-staging-%')->pluck('id')->all();

            $this->ensureProduct('products.bilingual', 'seo-staging-bilingual-001', $category, '001', true);
            $this->ensureProduct('products.single_locale', 'seo-staging-single-locale-001', $category, '002', false);
            $this->ensureProduct('products.additional.001', 'seo-staging-additional-001', $category, '003', true);
            $this->ensureProduct('products.additional.002', 'seo-staging-additional-002', $category, '004', true);

            $this->ensureSupportedContentPage();
            $this->ensurePolicyPage('returns-policy', 'returns');
            $this->ensurePolicyPage('shipping-policy', 'shipping');
            $this->ensureStoreSettings($configuration);

            if ($withPagination) {
                $this->ensurePaginationProducts($category);
            }

            $createdIds = Product::query()->where('slug', 'like', 'seo-staging-%')->pluck('id')->all();
            $createdCount = count(array_diff($createdIds, $initialProductIds));

            return [
                'products_created' => $createdCount,
                'products_eligible_ar' => $this->eligibleProductCount('ar'),
                'products_eligible_en' => $this->eligibleProductCount('en'),
            ];
        });
    }

    private function assertStagingEnvironment(): void
    {
        if (! app()->environment('staging')) {
            throw new RuntimeException('SEO acceptance fixtures may run only when APP_ENV=staging.');
        }

        if (app()->isProduction()) {
            throw new RuntimeException('SEO acceptance fixtures are forbidden in production.');
        }

        if (! Schema::hasTable('seo_staging_fixture_records')) {
            throw new RuntimeException('Run the staging fixture ownership-ledger migration before seeding.');
        }
    }

    /** @return array{store_name_ar: string, store_name_en: string, canonical_base_url: string, logo_media_id: ?int} */
    private function validatedConfiguration(): array
    {
        $arabicName = config('seo_staging_fixtures.store_name_ar');
        $englishName = config('seo_staging_fixtures.store_name_en');
        $canonicalBaseUrl = config('seo_staging_fixtures.canonical_base_url');

        if (! is_string($arabicName) || trim($arabicName) === '') {
            throw new RuntimeException('Set SEO_STAGING_STORE_NAME_AR to the operator-approved Arabic staging store name.');
        }

        if (! is_string($englishName) || trim($englishName) === '') {
            throw new RuntimeException('Set SEO_STAGING_STORE_NAME_EN to the operator-approved English staging store name.');
        }

        if (! is_string($canonicalBaseUrl) || ! $this->isHttpsOrigin($canonicalBaseUrl)) {
            throw new RuntimeException('Set SEO_STAGING_CANONICAL_BASE_URL to the absolute HTTPS staging origin.');
        }

        $applicationUrl = config('app.url');
        if (! is_string($applicationUrl) || ! $this->sameHttpsOrigin($canonicalBaseUrl, $applicationUrl)) {
            throw new RuntimeException('SEO_STAGING_CANONICAL_BASE_URL must match the configured HTTPS APP_URL staging origin.');
        }

        $logoMediaId = config('seo_staging_fixtures.logo_media_id');
        if ($logoMediaId !== null && $logoMediaId !== '') {
            if (filter_var($logoMediaId, FILTER_VALIDATE_INT) === false || (int) $logoMediaId < 1) {
                throw new RuntimeException('SEO_STAGING_LOGO_MEDIA_ID must identify an existing public HTTPS image asset.');
            }

            $asset = MediaAsset::query()
                ->whereKey((int) $logoMediaId)
                ->where('media_type', 'image')
                ->whereNull('deleted_at')
                ->first();
            $url = app(SeoPublicImageService::class)->url($asset);
            if ($url === null || ! $this->isAbsoluteHttpsUrl($url)) {
                throw new RuntimeException('SEO_STAGING_LOGO_MEDIA_ID must identify an active public image with an HTTPS URL.');
            }

            $logoMediaId = (int) $logoMediaId;
        } else {
            $logoMediaId = null;
        }

        return [
            'store_name_ar' => trim($arabicName),
            'store_name_en' => trim($englishName),
            'canonical_base_url' => rtrim($canonicalBaseUrl, '/'),
            'logo_media_id' => $logoMediaId,
        ];
    }

    private function ensureCategory(): Category
    {
        $category = $this->trackedModel('categories.acceptance', 'category', Category::class, 'seo-staging-category');
        if (! $category instanceof Category) {
            $category = new Category([
                'slug' => 'seo-staging-category',
                'name_ar' => self::PREFIX."\u{0641}\u{0626}\u{0629}",
                'name_en' => self::PREFIX.'Category',
                'description_ar' => self::PREFIX."\u{0648}\u{0635}\u{0641} \u{0627}\u{062E}\u{062A}\u{0628}\u{0627}\u{0631}\u{064A}",
                'description_en' => self::PREFIX.'Fixture category for storefront acceptance testing.',
                'status' => 'active',
                'sort_order' => 999,
            ]);
            $category->save();
            $this->track('categories.acceptance', 'category', $category);
        }

        return $category;
    }

    private function ensureProduct(
        string $fixtureKey,
        string $slug,
        Category $category,
        string $sequence,
        bool $bilingual,
    ): Product {
        $product = $this->trackedModel($fixtureKey, 'product', Product::class, $slug);
        if (! $product instanceof Product) {
            $localizedLabel = self::PREFIX."\u{0645}\u{0646}\u{062A}\u{062C}-".$sequence;
            $product = new Product([
                'slug' => $slug,
                'name_ar' => $localizedLabel,
                'name_en' => $bilingual ? self::PREFIX.'Product-'.$sequence : '',
                'description_ar' => self::PREFIX."\u{0648}\u{0635}\u{0641} \u{062A}\u{062C}\u{0631}\u{0628}\u{064A}",
                'description_en' => $bilingual ? self::PREFIX.'Acceptance fixture product '.$sequence.'.' : null,
                'features' => [],
                'specifications' => [],
                'status' => 'active',
                'is_featured' => false,
                'published_at' => now()->subMinute(),
            ]);
            $product->save();
            $this->track($fixtureKey, 'product', $product);
        }

        $product->categories()->syncWithoutDetaching([
            $category->getKey() => ['is_primary' => true],
        ]);

        $sku = 'SEO-STAGING-'.$sequence.'-001';
        $sellableItem = SellableItem::query()->where('sku', $sku)->first();
        if (! $sellableItem instanceof SellableItem) {
            if (SellableItem::withTrashed()->where('sku', $sku)->exists()) {
                throw new RuntimeException("Fixture SKU {$sku} is occupied by an existing record.");
            }

            $product->sellableItems()->create([
                'sku' => $sku,
                'price' => '1.00',
                'stock_quantity' => 10,
                'status' => 'active',
                'is_default' => true,
                'sort_order' => 1,
            ]);
        } elseif ((int) $sellableItem->product_id !== (int) $product->getKey()) {
            throw new RuntimeException("Fixture SKU {$sku} is owned by a different product.");
        }

        return $product;
    }

    private function ensureSupportedContentPage(): void
    {
        $slugs = config('content.page_slugs', []);
        foreach (array_unique(['about-us', ...$slugs]) as $slug) {
            $existing = ContentPage::query()->where('slug', $slug)->first();
            if ($existing instanceof ContentPage
                && (trim((string) $existing->title_ar) !== '' || trim((string) $existing->title_en) !== '')) {
                return;
            }

            if ($existing instanceof ContentPage || ! in_array($slug, $slugs, true)) {
                continue;
            }

            $page = $this->trackedModel('pages.supported', 'content_page', ContentPage::class, $slug);
            if (! $page instanceof ContentPage) {
                $page = new ContentPage([
                    'slug' => $slug,
                    'title_ar' => self::PREFIX."\u{0635}\u{0641}\u{062D}\u{0629} \u{0627}\u{062E}\u{062A}\u{0628}\u{0627}\u{0631}",
                    'title_en' => self::PREFIX.'Acceptance page',
                    'body_ar' => self::PREFIX."\u{0645}\u{062D}\u{062A}\u{0648}\u{0649} \u{0644}\u{0644}\u{0627}\u{062E}\u{062A}\u{0628}\u{0627}\u{0631} \u{0641}\u{0642}\u{0637}.",
                    'body_en' => self::PREFIX.'Acceptance fixture content only.',
                ]);
                $page->save();
                $this->track('pages.supported', 'content_page', $page, $this->contentPageFingerprint($page));
            }

            return;
        }

        throw new RuntimeException('No supported content page is available. Add an unused slug to content.page_slugs or provide a published staging page.');
    }

    private function ensurePolicyPage(string $slug, string $kind): void
    {
        $existing = ContentPage::query()->where('slug', $slug)->first();
        if ($existing instanceof ContentPage) {
            return;
        }

        $fixtureKey = 'pages.'.$kind.'_policy';
        $page = $this->trackedModel($fixtureKey, 'content_page', ContentPage::class, $slug);
        if (! $page instanceof ContentPage) {
            $page = new ContentPage([
                'slug' => $slug,
                'title_ar' => self::PREFIX."\u{0633}\u{064A}\u{0627}\u{0633}\u{0629} \u{0627}\u{062E}\u{062A}\u{0628}\u{0627}\u{0631}",
                'title_en' => self::PREFIX.'Acceptance fixture '.ucfirst($kind).' page',
                'body_ar' => self::PREFIX."\u{0647}\u{0630}\u{0647} \u{0635}\u{0641}\u{062D}\u{0629} \u{062A}\u{062C}\u{0631}\u{064A}\u{0628}\u{064A}\u{0629} \u{0641}\u{0642}\u{0637} \u{0648}\u{0644}\u{064A}\u{0633}\u{062A} \u{0633}\u{064A}\u{0627}\u{0633}\u{0629} \u{0645}\u{062A}\u{062C}\u{0631} \u{062D}\u{0642}\u{064A}\u{0642}\u{064A}\u{0629}.",
                'body_en' => self::PREFIX.'This is a staging acceptance fixture, not a store policy.',
            ]);
            $page->save();
            $this->track($fixtureKey, 'content_page', $page, $this->contentPageFingerprint($page));
        }
    }

    /** @param array{store_name_ar: string, store_name_en: string, canonical_base_url: string, logo_media_id: ?int} $configuration */
    private function ensureStoreSettings(array $configuration): void
    {
        foreach ([
            ['store_name', 'ar', $configuration['store_name_ar']],
            ['store_name', 'en', $configuration['store_name_en']],
            ['canonical_base_url', '', $configuration['canonical_base_url']],
        ] as [$key, $locale, $value]) {
            $this->createSettingIfMissing($key, $locale, $value);
        }

        if ($configuration['logo_media_id'] !== null) {
            $this->createSettingIfMissing('store_logo_id', '', $configuration['logo_media_id']);
        }
    }

    private function createSettingIfMissing(string $key, string $locale, string|int $value): void
    {
        $existing = SeoSetting::query()->where('key', $key)->where('locale', $locale)->first();
        if ($existing instanceof SeoSetting) {
            if ($existing->value_json !== $value) {
                throw new RuntimeException("Existing SEO setting {$key} ({$locale}) differs from the supplied fixture configuration; update it through the admin workflow first.");
            }

            return;
        }

        $setting = SeoSetting::query()->create([
            'key' => $key,
            'locale' => $locale,
            'value_json' => $value,
        ]);
        $fingerprint = hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
        $this->track('settings.'.$key.'.'.($locale === '' ? 'global' : $locale), 'seo_setting', $setting, $fingerprint);
    }

    private function ensurePaginationProducts(Category $category): void
    {
        $sequence = 5;
        while (min($this->eligibleProductCount('ar'), $this->eligibleProductCount('en')) < 25) {
            $number = str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
            $this->ensureProduct(
                'products.pagination.'.$number,
                'seo-staging-pagination-'.$number,
                $category,
                $number,
                true,
            );
            $sequence++;
        }
    }

    private function eligibleProductCount(string $locale): int
    {
        $visibleCategoryIds = app(CategoryHierarchyService::class)->effectiveVisibleIds();
        $column = $locale === 'ar' ? 'name_ar' : 'name_en';

        return Product::query()->visible($visibleCategoryIds)
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->count();
    }

    /** @param class-string<Model> $modelClass */
    private function trackedModel(string $fixtureKey, string $recordType, string $modelClass, string $slug): ?Model
    {
        $ledger = DB::table('seo_staging_fixture_records')->where('fixture_key', $fixtureKey)->first();
        $query = $modelClass::query();
        if (in_array($recordType, ['product', 'category'], true)) {
            $query->withTrashed();
        }

        if ($ledger !== null) {
            if ($ledger->record_type !== $recordType) {
                throw new RuntimeException("Fixture ownership record {$fixtureKey} has an unexpected type.");
            }

            $model = $query->find($ledger->record_id);
            if ($model instanceof Model) {
                if (method_exists($model, 'trashed') && $model->trashed()) {
                    throw new RuntimeException("Fixture record {$fixtureKey} is archived; restore it through the admin workflow before seeding again.");
                }

                if ($model->getAttribute('slug') !== $slug) {
                    throw new RuntimeException("Fixture record {$fixtureKey} no longer has its reserved slug; review it through the admin workflow before seeding again.");
                }

                return $model;
            }

            DB::table('seo_staging_fixture_records')->where('id', $ledger->id)->delete();
        }

        $slugQuery = $modelClass::query();
        if (in_array($recordType, ['product', 'category'], true)) {
            $slugQuery->withTrashed();
        }

        if ($slugQuery->where('slug', $slug)->exists()) {
            throw new RuntimeException("Reserved fixture slug {$slug} already belongs to a record not created by this fixture.");
        }

        return null;
    }

    private function track(string $fixtureKey, string $recordType, Model $model, ?string $fingerprint = null): void
    {
        DB::table('seo_staging_fixture_records')->insert([
            'fixture_key' => $fixtureKey,
            'record_type' => $recordType,
            'record_id' => $model->getKey(),
            'record_fingerprint' => $fingerprint,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function contentPageFingerprint(ContentPage $page): string
    {
        return hash('sha256', json_encode([
            $page->slug,
            $page->title_ar,
            $page->title_en,
            $page->body_ar,
            $page->body_en,
        ], JSON_THROW_ON_ERROR));
    }

    private function isHttpsOrigin(string $url): bool
    {
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && (! isset($parts['port']) || (int) $parts['port'] === 443)
            && in_array($parts['path'] ?? '', ['', '/'], true)
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    private function isAbsoluteHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    private function sameHttpsOrigin(string $left, string $right): bool
    {
        if (! $this->isHttpsOrigin($left) || ! $this->isHttpsOrigin($right)) {
            return false;
        }

        $leftParts = parse_url($left);
        $rightParts = parse_url($right);

        return strtolower((string) $leftParts['host']) === strtolower((string) $rightParts['host'])
            && (int) ($leftParts['port'] ?? 443) === (int) ($rightParts['port'] ?? 443);
    }
}
