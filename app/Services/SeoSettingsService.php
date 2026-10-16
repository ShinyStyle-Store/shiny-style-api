<?php

namespace App\Services;

use App\Models\SeoSetting;
use App\Models\User;
use App\Support\SeoSettingCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SeoSettingsService
{
    public function __construct(private readonly SeoAuditService $audits) {}

    /** @return Collection<int, SeoSetting> */
    public function all(): Collection
    {
        return SeoSetting::query()->orderBy('key')->orderBy('locale')->get();
    }

    public function find(string $apiKey, ?string $locale = null): ?SeoSetting
    {
        $definition = SeoSettingCatalog::definition($apiKey);
        if ($definition === null) {
            return null;
        }

        return SeoSetting::query()
            ->where('key', $definition['key'])
            ->where('locale', $definition['localized'] ? ($locale ?? app()->getLocale()) : '')
            ->first();
    }

    /** @param list<array{key: string, locale: string, value: mixed}> $settings */
    public function update(array $settings, User $actor): Collection
    {
        return DB::transaction(function () use ($settings, $actor): Collection {
            $changedLocales = [];
            foreach ($settings as $setting) {
                if ($this->updateOne($setting, $actor)) {
                    $locale = $setting['locale'];
                    $changedLocales = array_merge(
                        $changedLocales,
                        $locale === '' ? ['ar', 'en'] : [$locale],
                    );
                }
            }

            if ($changedLocales !== []) {
                app(SeoRevalidationService::class)->afterCommitForTarget(
                    'store',
                    'store',
                    'updated',
                    array_values(array_unique($changedLocales)),
                    ['catalog', 'content', 'store', 'sitemap', 'redirects', 'metaFeed'],
                );
            }

            return $this->all();
        });
    }

    /** @param array{key: string, locale: string, value: mixed} $setting */
    private function updateOne(array $setting, User $actor): bool
    {
        $query = SeoSetting::query()
            ->where('key', $setting['key'])
            ->where('locale', $setting['locale']);
        $existing = $query->lockForUpdate()->first();
        $apiKey = SeoSettingCatalog::apiKey($setting['key']) ?? $setting['key'];
        $locale = $setting['locale'] === '' ? null : $setting['locale'];

        if ($setting['value'] === null) {
            if ($existing === null) {
                return false;
            }

            $before = ['key' => $apiKey, 'locale' => $locale, 'value' => $existing->value_json];
            $targetId = (int) $existing->getKey();
            $existing->delete();
            $this->audits->record((int) $actor->getKey(), 'seo_setting', $targetId, 'delete', $before, null);

            return true;
        }

        if ($existing !== null && $existing->value_json === $setting['value']) {
            return false;
        }

        $creating = $existing === null;
        if ($creating) {
            $now = now();
            $inserted = DB::table('seo_settings')->insertOrIgnore([
                'key' => $setting['key'],
                'locale' => $setting['locale'],
                'value_json' => json_encode($setting['value'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $existing = $query->lockForUpdate()->firstOrFail();
            $creating = $inserted === 1;
        }

        if (! $creating && $existing->value_json === $setting['value']) {
            return false;
        }

        $before = $creating
            ? null
            : ['key' => $apiKey, 'locale' => $locale, 'value' => $existing->value_json];
        $targetId = (int) $existing->getKey();

        if (! $creating) {
            $existing->value_json = $setting['value'];
            $existing->save();
        }

        $after = ['key' => $apiKey, 'locale' => $locale, 'value' => $setting['value']];
        $this->audits->record(
            (int) $actor->getKey(),
            'seo_setting',
            $targetId,
            $creating ? 'create' : 'update',
            $before,
            $after,
        );

        return true;
    }
}
