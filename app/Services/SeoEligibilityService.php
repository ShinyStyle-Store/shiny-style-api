<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

final class SeoEligibilityService
{
    /** @param list<string> $supportedLocales
     *  @return list<string>
     */
    public function availableLocales(Model $entity, array $supportedLocales): array
    {
        $translations = match (true) {
            $entity instanceof Product, $entity instanceof Category => [
                'ar' => $entity->name_ar,
                'en' => $entity->name_en,
            ],
            $entity instanceof ContentPage => [
                'ar' => $entity->title_ar,
                'en' => $entity->title_en,
            ],
            default => [],
        };

        return array_values(array_filter(
            ['ar', 'en'],
            static fn (string $locale): bool => in_array($locale, $supportedLocales, true)
                && is_string($translations[$locale] ?? null)
                && trim($translations[$locale]) !== '',
        ));
    }

    /**
     * The public controller calls this only after its existing visibility query succeeds.
     * This keeps indexing policy separate from public visibility and does not hide the page.
     *
     * @param list<string> $availableLocales
     * @param array{index: bool, follow: bool} $robots
     * @return array{robots: array{index: bool, follow: bool}, indexable: bool}
     */
    public function resolve(
        bool $isPubliclyVisible,
        string $locale,
        array $availableLocales,
        array $robots,
    ): array {
        $indexable = $isPubliclyVisible
            && (bool) config('seo.indexing_allowed', false)
            && in_array($locale, $availableLocales, true)
            && $robots['index'];

        return [
            'robots' => [
                'index' => $indexable,
                'follow' => $robots['follow'],
            ],
            'indexable' => $indexable,
        ];
    }
}
