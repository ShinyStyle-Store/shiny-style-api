<?php

namespace App\Services;

use App\Support\SeoSettingCatalog;

final class SeoPublicSettingsService
{
    public function __construct(
        private readonly SeoSettingsService $settings,
        private readonly SeoResolverService $resolver,
    ) {}

    /** @return array{locale: string, pages: array<string, array<string, mixed>>} */
    public function resolve(): array
    {
        $settings = $this->settings->all();
        $pages = [];

        foreach (array_keys(SeoSettingCatalog::PUBLIC_PAGES) as $pageKey) {
            $pages[$pageKey] = $this->resolver->resolvePublicPage($pageKey, $settings);
        }

        return [
            'locale' => app()->getLocale(),
            'pages' => $pages,
        ];
    }
}
