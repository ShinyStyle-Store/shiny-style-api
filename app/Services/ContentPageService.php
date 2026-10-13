<?php

namespace App\Services;

use App\Models\ContentPage;
use Illuminate\Support\Facades\DB;

class ContentPageService
{
    /** @return array<int, ContentPage> */
    public function all(): array
    {
        $slugs = config('content.page_slugs', []);
        $existing = ContentPage::query()->whereIn('slug', $slugs)->get()->keyBy('slug');

        return array_map(
            fn (string $slug): ContentPage => $existing->get($slug) ?? new ContentPage(['slug' => $slug]),
            $slugs,
        );
    }

    public function find(string $slug): ?ContentPage
    {
        return ContentPage::query()->where('slug', $slug)->first();
    }

    public function update(string $slug, array $data): ContentPage
    {
        return DB::transaction(function () use ($slug, $data): ContentPage {
            $now = now();
            DB::table('content_pages')->insertOrIgnore([
                'slug' => $slug,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $page = ContentPage::query()
                ->where('slug', $slug)
                ->lockForUpdate()
                ->firstOrFail();
            $page->fill($data);
            $dirtyFields = array_keys($page->getDirty());
            $page->save();

            $localizedFields = [
                'ar' => ['title_ar', 'body_ar'],
                'en' => ['title_en', 'body_en'],
            ];
            $sharedFields = array_diff($dirtyFields, ...array_values($localizedFields));
            $significantChanges = app(SeoSignificantModificationService::class);
            if ($sharedFields !== []) {
                $significantChanges->mark($page);
            }
            foreach ($localizedFields as $locale => $fields) {
                if (array_intersect($dirtyFields, $fields) !== []) {
                    $significantChanges->mark($page, locale: $locale);
                }
            }

            return $page->refresh();
        });
    }
}
