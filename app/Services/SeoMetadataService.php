<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\SeoRecord;
use App\Support\SeoCanonicalPath;
use App\Support\SeoSettingCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class SeoMetadataService
{
    public function __construct(private readonly SeoPublicImageService $publicImages) {}

    /** @param array<string, mixed> $presentation
     *  @param Collection<int, mixed> $settings
     *  @return array{title: ?string, description: ?string, canonicalPath: ?string, robots: array{index: bool, follow: bool}, openGraph: array{title: ?string, description: ?string, image: ?string}}
     */
    public function build(
        Model $entity,
        array $presentation,
        ?SeoRecord $record,
        Collection $settings,
        string $locale,
    ): array {
        $title = $this->resolveTitle($presentation, $record, $settings, $locale)['title'];
        $description = $this->resolveDescription($presentation, $record, $settings, $locale);
        $ogTitle = $this->cleanText($record?->og_title) ?? $title;
        $ogDescription = $this->cleanText($record?->og_description) ?? $description;
        $openGraphImage = $this->resolveOpenGraphImage($entity, $presentation, $record, $settings);

        return [
            'title' => $title,
            'description' => $description,
            'canonicalPath' => $this->canonicalPath($entity, $record?->canonical_path),
            'robots' => $this->robots($record, $settings),
            'openGraph' => [
                'title' => $ogTitle,
                'description' => $ogDescription,
                'image' => $openGraphImage['image'],
            ],
        ];
    }

    /**
     * @param array{canonicalPath: string, titleSetting: string, descriptionSetting: string, robotsSetting: string, imageSetting: string} $page
     * @param Collection<int, mixed> $settings
     * @return array{
     *     seo: array{title: ?string, description: ?string, canonicalPath: string, robots: array{index: bool, follow: bool}, openGraph: array{title: ?string, description: ?string, image: ?string}},
     *     settingKeys: list<string>,
     *     assetIds: list<int>
     * }
     */
    public function buildFixedPage(
        array $page,
        Collection $settings,
        string $locale,
    ): array {
        $pageTitle = $this->truncate(
            $this->cleanText($this->settingValue($settings, $page['titleSetting'], $locale)),
            180,
        );
        $titleFallback = $this->resolveTitle([], null, $settings, $locale);
        $title = $pageTitle ?? $titleFallback['title'];

        $pageDescription = $this->cleanText(
            $this->settingValue($settings, $page['descriptionSetting'], $locale),
        );
        $descriptionFallback = $this->resolveDescriptionWithDependencies([], null, $settings, $locale);
        $description = $pageDescription ?? $descriptionFallback['description'];

        $pageRobots = $this->settingValue($settings, $page['robotsSetting']);
        $defaultRobots = $this->robots(null, $settings);
        $robots = $defaultRobots;
        $settingKeys = [$page['titleSetting'], $page['descriptionSetting'], $page['robotsSetting']];
        if ($pageTitle === null) {
            $settingKeys = [...$settingKeys, ...$titleFallback['settingKeys']];
        }
        if ($pageDescription === null && $descriptionFallback['usesDefaultMetaDescription']) {
            $settingKeys[] = 'defaultMetaDescription';
        }

        if (is_array($pageRobots)) {
            foreach (['index', 'follow'] as $directive) {
                if (is_bool($pageRobots[$directive] ?? null)) {
                    $robots[$directive] = $pageRobots[$directive];
                } else {
                    $settingKeys[] = 'defaultRobotsPolicy';
                }
            }
        } else {
            $settingKeys[] = 'defaultRobotsPolicy';
        }

        $image = $this->resolveFixedPageImage($page['imageSetting'], $settings);
        $settingKeys = [...$settingKeys, ...$image['settingKeys']];
        $settingKeys = array_values(array_unique($settingKeys));

        return [
            'seo' => [
                'title' => $title,
                'description' => $description,
                'canonicalPath' => $page['canonicalPath'],
                'robots' => $robots,
                'openGraph' => [
                    'title' => $title,
                    'description' => $description,
                    'image' => $image['image'],
                ],
            ],
            'settingKeys' => $settingKeys,
            'assetIds' => $image['assetIds'],
        ];
    }

    /** @return array{canonicalPath: ?string, robots: array{index: bool, follow: bool}} */
    public function sitemapPolicy(Model $entity, ?SeoRecord $record, Collection $settings): array
    {
        return [
            'canonicalPath' => $this->canonicalPath($entity, $record?->canonical_path),
            'robots' => $this->robots($record, $settings),
        ];
    }

    /** @param array<string, mixed> $presentation
     *  @param Collection<int, mixed> $settings
     */
    public function resolveDescription(
        array $presentation,
        ?SeoRecord $record,
        Collection $settings,
        string $locale,
    ): ?string {
        return $this->resolveDescriptionWithDependencies($presentation, $record, $settings, $locale)['description'];
    }

    /** @param array<string, mixed> $presentation
     *  @param Collection<int, mixed> $settings
     *  @return array{description: ?string, usesDefaultMetaDescription: bool}
     */
    public function resolveDescriptionWithDependencies(
        array $presentation,
        ?SeoRecord $record,
        Collection $settings,
        string $locale,
    ): array {
        $customDescription = $this->cleanText($record?->meta_description);
        $sourceDescription = $presentation['description'] ?? $presentation['body'] ?? null;
        $contentDescription = $this->truncate($this->cleanText($sourceDescription), 160);
        $usesDefaultMetaDescription = $customDescription === null && $contentDescription === null;

        return [
            'description' => $customDescription
                ?? $contentDescription
                ?? $this->cleanText($this->settingValue($settings, 'default_meta_description', $locale)),
            'usesDefaultMetaDescription' => $usesDefaultMetaDescription,
        ];
    }

    /**
     * @param array<string, mixed> $presentation
     * @param Collection<int, mixed> $settings
     * @return array{title: ?string, settingKeys: list<string>}
     */
    public function resolveTitle(
        array $presentation,
        ?SeoRecord $record,
        Collection $settings,
        string $locale,
    ): array {
        $customTitle = $this->cleanText($record?->meta_title);
        if ($customTitle !== null) {
            return ['title' => $customTitle, 'settingKeys' => []];
        }

        $name = $presentation['name'] ?? $presentation['title'] ?? null;
        $contentTitle = $this->truncate($this->cleanText($name), 180);
        if ($contentTitle !== null) {
            return ['title' => $contentTitle, 'settingKeys' => []];
        }

        $defaultTitle = $this->truncate(
            $this->cleanText($this->settingValue($settings, 'default_seo_title', $locale)),
            180,
        );
        if ($defaultTitle !== null) {
            return ['title' => $defaultTitle, 'settingKeys' => ['defaultSeoTitle']];
        }

        $storeName = $this->truncate(
            $this->cleanText($this->settingValue($settings, 'store_name', $locale)),
            180,
        );

        return ['title' => $storeName, 'settingKeys' => ['defaultSeoTitle', 'storeName']];
    }

    private function canonicalPath(Model $entity, ?string $customPath): ?string
    {
        $defaultPath = SeoCanonicalPath::expectedPath($entity);

        if ($defaultPath === null) {
            return null;
        }

        if ($customPath !== null
            && SeoCanonicalPath::isSafeInternalPath($customPath)
            && SeoCanonicalPath::matchesEntity($entity, $customPath)) {
            return $customPath;
        }

        return $defaultPath;
    }

    /** @return array{index: bool, follow: bool} */
    private function robots(?SeoRecord $record, Collection $settings): array
    {
        $defaultPolicy = $this->settingValue($settings, 'default_robots_policy');
        $defaultPolicy = is_array($defaultPolicy) ? $defaultPolicy : [];

        return [
            'index' => is_bool($record?->robots_index)
                ? $record->robots_index
                : (is_bool($defaultPolicy['index'] ?? null) ? $defaultPolicy['index'] : true),
            'follow' => is_bool($record?->robots_follow)
                ? $record->robots_follow
                : (is_bool($defaultPolicy['follow'] ?? null) ? $defaultPolicy['follow'] : true),
        ];
    }

    /** @param array<string, mixed> $presentation
     *  @param Collection<int, mixed> $settings
     *  @return array{image: ?string, settingKeys: list<string>, assetIds: list<int>}
     */
    public function resolveOpenGraphImage(
        Model $entity,
        array $presentation,
        ?SeoRecord $record,
        Collection $settings,
    ): array {
        $customImage = $record?->ogImage;
        $customImageUrl = $customImage instanceof MediaAsset ? $this->publicImages->url($customImage) : null;
        if ($customImageUrl !== null) {
            return ['image' => $customImageUrl, 'settingKeys' => [], 'assetIds' => [(int) $customImage->getKey()]];
        }

        $contentImageAsset = $presentation['imageAsset'] ?? null;
        $contentImageUrl = $contentImageAsset instanceof MediaAsset
            ? $this->publicImages->url($contentImageAsset)
            : null;
        if ($contentImageUrl !== null) {
            return [
                'image' => $contentImageUrl,
                'settingKeys' => [],
                'assetIds' => [(int) $contentImageAsset->getKey()],
            ];
        }

        $fallbacks = [[
            'key' => 'defaultOgImageId',
            'id' => $this->settingValue($settings, 'default_og_image_id'),
        ]];
        if ($entity instanceof ContentPage) {
            $fallbacks[] = [
                'key' => 'storeLogoId',
                'id' => $this->settingValue($settings, 'store_logo_id'),
            ];
        }
        $imageIds = array_values(array_filter(array_column($fallbacks, 'id'), static fn (mixed $id): bool =>
            filter_var($id, FILTER_VALIDATE_INT) !== false));
        $images = MediaAsset::query()->whereIn('id', $imageIds)->get()->keyBy(
            static fn (MediaAsset $asset): int => (int) $asset->getKey(),
        );
        $usedSettingKeys = [];
        $usedAssetIds = [];
        foreach ($fallbacks as $fallback) {
            $imageId = $fallback['id'];
            if (filter_var($imageId, FILTER_VALIDATE_INT) === false) {
                $usedSettingKeys[] = $fallback['key'];
                continue;
            }

            $usedSettingKeys[] = $fallback['key'];
            $candidate = $images->get((int) $imageId);
            $candidateUrl = $candidate instanceof MediaAsset ? $this->publicImages->url($candidate) : null;
            if ($candidateUrl !== null) {
                $usedAssetIds[] = (int) $candidate->getKey();

                return [
                    'image' => $candidateUrl,
                    'settingKeys' => $usedSettingKeys,
                    'assetIds' => $usedAssetIds,
                ];
            }
        }

        return ['image' => null, 'settingKeys' => $usedSettingKeys, 'assetIds' => $usedAssetIds];
    }

    /** @param Collection<int, mixed> $settings
     *  @return array{image: ?string, settingKeys: list<string>, assetIds: list<int>}
     */
    private function resolveFixedPageImage(string $pageSetting, Collection $settings): array
    {
        $pageAssetId = $this->settingValue($settings, $pageSetting);
        $pageAsset = $this->assetForSetting($pageAssetId);
        $pageImageUrl = $pageAsset instanceof MediaAsset ? $this->publicImages->url($pageAsset) : null;
        $pageImageAssetId = $this->assetId($pageAssetId);
        $assetIds = $pageImageAssetId === null ? [] : [$pageImageAssetId];

        if ($pageImageUrl !== null) {
            return ['image' => $pageImageUrl, 'settingKeys' => [$pageSetting], 'assetIds' => $assetIds];
        }

        $defaultAssetId = $this->settingValue($settings, 'defaultOgImageId');
        $defaultAsset = $this->assetForSetting($defaultAssetId);
        $defaultImageUrl = $defaultAsset instanceof MediaAsset ? $this->publicImages->url($defaultAsset) : null;
        $defaultId = $this->assetId($defaultAssetId);
        if ($defaultId !== null) {
            $assetIds[] = $defaultId;
        }

        return [
            'image' => $defaultImageUrl,
            'settingKeys' => [$pageSetting, 'defaultOgImageId'],
            'assetIds' => array_values(array_unique($assetIds)),
        ];
    }

    private function assetForSetting(mixed $assetId): ?MediaAsset
    {
        $id = $this->assetId($assetId);
        if ($id === null) {
            return null;
        }

        $asset = MediaAsset::query()->find($id);

        return $asset instanceof MediaAsset ? $asset : null;
    }

    private function assetId(mixed $assetId): ?int
    {
        return filter_var($assetId, FILTER_VALIDATE_INT) !== false && (int) $assetId > 0
            ? (int) $assetId
            : null;
    }

    private function settingValue(Collection $settings, string $key, ?string $locale = null): mixed
    {
        $databaseKey = SeoSettingCatalog::definition($key)['key'] ?? $key;

        return $settings->first(static fn ($setting): bool => $setting->key === $databaseKey
            && $setting->locale === ($locale ?? ''))?->value_json;
    }

    private function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = preg_replace('/<(?:br|hr)\b[^>]*>|<\/(?:p|div|li|h[1-6]|section|article|blockquote)\s*>/i', ' ', $value);
        $text = html_entity_decode(strip_tags(is_string($text) ? $text : $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\p{Z}\s]+/u', ' ', $text);
        $text = is_string($text) ? trim($text) : '';

        return $text !== '' ? $text : null;
    }

    public function cleanPublicText(mixed $value): ?string
    {
        return $this->cleanText($value);
    }

    private function truncate(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length, 'UTF-8');
    }
}
