<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\SeoRecord;
use App\Support\SeoCanonicalPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class SeoMetadataService
{
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
        $name = $presentation['name'] ?? $presentation['title'] ?? null;
        $sourceDescription = $presentation['description'] ?? $presentation['body'] ?? null;
        $customTitle = $this->cleanText($record?->meta_title);
        $customDescription = $this->cleanText($record?->meta_description);
        $defaultTitle = $this->settingValue($settings, 'default_seo_title', $locale);
        $storeName = $this->settingValue($settings, 'store_name', $locale);
        $defaultDescription = $this->settingValue($settings, 'default_meta_description', $locale);
        $title = $customTitle
            ?? $this->truncate($this->cleanText($name), 180)
            ?? $this->truncate($this->cleanText($defaultTitle), 180)
            ?? $this->truncate($this->cleanText($storeName), 180);
        $description = $customDescription
            ?? $this->truncate($this->cleanText($sourceDescription), 160)
            ?? $this->cleanText($defaultDescription);
        $ogTitle = $this->cleanText($record?->og_title) ?? $title;
        $ogDescription = $this->cleanText($record?->og_description) ?? $description;

        return [
            'title' => $title,
            'description' => $description,
            'canonicalPath' => $this->canonicalPath($entity, $record?->canonical_path),
            'robots' => $this->robots($record, $settings),
            'openGraph' => [
                'title' => $ogTitle,
                'description' => $ogDescription,
                'image' => $this->openGraphImage($entity, $presentation, $record, $settings),
            ],
        ];
    }

    private function canonicalPath(Model $entity, ?string $customPath): ?string
    {
        if ($entity instanceof ContentPage) {
            return null;
        }

        $defaultPath = match (true) {
            $entity instanceof Product => '/products/'.$entity->slug,
            $entity instanceof Category => '/category/'.$entity->slug,
            default => null,
        };

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

    /** @param array<string, mixed> $presentation */
    private function openGraphImage(
        Model $entity,
        array $presentation,
        ?SeoRecord $record,
        Collection $settings,
    ): ?string {
        $customImage = $record?->ogImage;
        if ($customImage instanceof MediaAsset && $customImage->media_type === 'image' && ! $customImage->trashed()) {
            return $this->mediaUrl($customImage);
        }

        $contentImage = $presentation['image'] ?? null;
        if (is_string($contentImage) && $contentImage !== '') {
            return $contentImage;
        }

        $imageIds = array_filter([
            $this->settingValue($settings, 'default_og_image_id'),
            $entity instanceof ContentPage ? $this->settingValue($settings, 'store_logo_id') : null,
        ], static fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false);

        if ($imageIds === []) {
            return null;
        }

        $images = MediaAsset::query()
            ->whereIn('id', $imageIds)
            ->where('media_type', 'image')
            ->get()
            ->keyBy(fn (MediaAsset $asset): int => (int) $asset->getKey());

        $image = null;
        foreach ($imageIds as $imageId) {
            $candidate = $images->get((int) $imageId);
            if ($candidate instanceof MediaAsset) {
                $image = $candidate;
                break;
            }
        }

        return $image instanceof MediaAsset ? $this->mediaUrl($image) : null;
    }

    private function mediaUrl(MediaAsset $asset): string
    {
        return Storage::disk($asset->disk)->url($asset->path);
    }

    private function settingValue(Collection $settings, string $key, ?string $locale = null): mixed
    {
        return $settings->first(static fn ($setting): bool => $setting->key === $key
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

    private function truncate(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length, 'UTF-8');
    }
}
