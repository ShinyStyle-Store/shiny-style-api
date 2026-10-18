<?php

namespace App\Services;

use App\Http\Resources\SocialLinkResource;
use App\Http\Resources\StoreContactResource;
use App\Models\ContentPage;
use App\Models\MediaAsset;
use App\Models\SeoSetting;
use App\Models\SocialLink;
use App\Models\StoreContact;
use App\Support\SeoCanonicalPath;
use App\Support\SeoCanonicalOrigin;
use App\Support\SeoSettingCatalog;
use App\Support\SocialLinkUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class StoreStructuredDataService
{
    private const PROFILE_PLATFORMS = ['facebook', 'instagram', 'tiktok'];

    public function __construct(
        private readonly SeoSettingsService $settings,
        private readonly SeoMetadataService $metadata,
        private readonly SeoPublicImageService $images,
        private readonly StoreContactService $contacts,
        private readonly ContentPageService $contentPages,
        private readonly SeoStructuredDataService $structuredData,
    ) {}

    /** @return array{organizationId: ?string, name: ?string, url: ?string, logo: ?string} */
    public function identity(Collection $settings, string $locale): array
    {
        $name = $this->metadata->cleanPublicText($this->settingValue($settings, 'storeName', $locale));
        $url = SeoCanonicalOrigin::normalize($this->settingValue($settings, 'canonicalBaseUrl'));

        return [
            'organizationId' => $url === null ? null : $url.'/#organization',
            'name' => $name,
            'url' => $url,
            'logo' => $this->logoUrl($this->settingValue($settings, 'storeLogoId')),
        ];
    }

    /**
     * @return array{
     *     organization: array{organizationId: ?string, name: ?string, url: ?string, logo: ?string, contact: array{supportPhone: ?string, supportWhatsapp: ?string, supportEmail: ?string, address: ?string, googleMapsUrl: ?string}, sameAs: list<string>},
     *     website: array{websiteId: ?string, name: ?string, url: ?string, inLanguage: string, publisherId: ?string},
     *     currency: string,
     *     policies: array{returns: ?array{contentPageSlug: string, path: string, url: ?string}, shipping: ?array{contentPageSlug: string, path: string, url: ?string}}
     * }
     */
    public function publicProfile(Request $request): array
    {
        $settings = $this->settings->all();
        $locale = app()->getLocale();
        $identity = $this->identity($settings, $locale);
        $contact = (new StoreContactResource($this->contacts->current() ?? new StoreContact))
            ->resolve($request);
        $contact['googleMapsUrl'] = $this->publicGoogleMapsUrl($contact['googleMapsUrl']);
        $sameAs = SocialLink::query()
            ->enabled()
            ->ordered()
            ->get()
            ->filter(fn (SocialLink $link): bool => in_array($link->platform_code, self::PROFILE_PLATFORMS, true)
                && SocialLinkUrl::isValidForPlatform($link->platform_code, $link->url))
            ->map(fn (SocialLink $link): string => (new SocialLinkResource($link))->resolve($request)['url'])
            ->values()
            ->all();

        return $this->structuredData->storeProfile(
            $identity,
            $contact,
            $sameAs,
            (string) config('store.currency'),
            $locale,
            $this->policyReferences($identity['url']),
        );
    }

    private function settingValue(Collection $settings, string $apiKey, ?string $locale = null): mixed
    {
        $definition = SeoSettingCatalog::definition($apiKey);
        if ($definition === null) {
            return null;
        }

        $setting = $settings->first(static fn (SeoSetting $setting): bool => $setting->key === $definition['key']
            && $setting->locale === ($locale ?? ''));

        return $setting instanceof SeoSetting ? $setting->value_json : null;
    }

    private function logoUrl(mixed $assetId): ?string
    {
        if (filter_var($assetId, FILTER_VALIDATE_INT) === false || (int) $assetId < 1) {
            return null;
        }

        $asset = MediaAsset::query()
            ->whereKey((int) $assetId)
            ->where('media_type', 'image')
            ->whereNull('deleted_at')
            ->first();

        $url = $this->images->url($asset);
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
                ? $url
                : null;
    }

    private function publicGoogleMapsUrl(mixed $value): ?string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($value);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! in_array(strtolower((string) ($parts['host'] ?? '')), ['maps.google.com', 'maps.app.goo.gl'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return null;
        }

        return $value;
    }

    /** @return array{returns: ?array{contentPageSlug: string, path: string, url: ?string}, shipping: ?array{contentPageSlug: string, path: string, url: ?string}} */
    private function policyReferences(?string $origin): array
    {
        $pages = collect($this->contentPages->all())->keyBy('slug');
        $references = [];

        foreach ([
            'returns' => 'returns-policy',
            'shipping' => 'shipping-policy',
        ] as $policy => $slug) {
            $page = $pages->get($slug);
            $path = $page instanceof ContentPage && $page->exists
                ? SeoCanonicalPath::expectedPath($page)
                : null;

            $references[$policy] = $path === null ? null : [
                'contentPageSlug' => $slug,
                'path' => $path,
                'url' => $origin === null ? null : $origin.$path,
            ];
        }

        return $references;
    }
}
