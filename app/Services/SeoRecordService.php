<?php

namespace App\Services;

use App\Models\SeoRecord;
use App\Models\User;
use App\Support\SeoCanonicalPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SeoRecordService
{
    private const CUSTOM_FIELDS = [
        'meta_title',
        'meta_description',
        'canonical_path',
        'robots_index',
        'robots_follow',
        'og_title',
        'og_description',
        'og_image_id',
    ];

    public function __construct(private readonly SeoAuditService $audits) {}

    /** @return Collection<int, SeoRecord> */
    public function records(Model $entity): Collection
    {
        return SeoRecord::query()
            ->where('seoable_type', $entity->getMorphClass())
            ->where('seoable_id', $entity->getKey())
            ->orderBy('locale')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function update(Model $entity, array $data, User $actor): ?SeoRecord
    {
        if (array_key_exists('canonical_path', $data)) {
            SeoCanonicalPath::validateForEntity($entity, $data['canonical_path']);
        }

        $locale = (string) $data['locale'];
        unset($data['locale']);

        return DB::transaction(function () use ($entity, $locale, $data, $actor): ?SeoRecord {
            $query = SeoRecord::query()
                ->where('seoable_type', $entity->getMorphClass())
                ->where('seoable_id', $entity->getKey())
                ->where('locale', $locale);
            $record = $query->lockForUpdate()->first();
            $creating = $record === null;

            if ($creating && $this->hasCustomValue($data)) {
                $now = now();
                $inserted = DB::table('seo_records')->insertOrIgnore([
                    'seoable_type' => $entity->getMorphClass(),
                    'seoable_id' => $entity->getKey(),
                    'locale' => $locale,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $record = $query->lockForUpdate()->firstOrFail();
                $creating = $inserted === 1;
            }

            if ($record === null) {
                return null;
            }

            $before = $this->snapshot($record);
            $record->fill($data);
            $after = $this->snapshot($record);

            if ($before === $after) {
                return $record;
            }

            $targetType = (string) $entity->getMorphClass();
            $targetId = (int) $entity->getKey();

            if (! $this->hasCustomValue($record->only(self::CUSTOM_FIELDS))) {
                $record->delete();
                $this->audits->record((int) $actor->getKey(), $targetType, $targetId, 'delete', $before, null);

                return null;
            }

            $record->save();
            $this->audits->record(
                (int) $actor->getKey(),
                $targetType,
                $targetId,
                $creating ? 'create' : 'update',
                $creating ? null : $before,
                $after,
            );

            return $record->refresh();
        });
    }

    /** @param array<string, mixed> $values */
    private function hasCustomValue(array $values): bool
    {
        foreach (self::CUSTOM_FIELDS as $field) {
            if (($values[$field] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $values */
    private function snapshot(array|SeoRecord $values): array
    {
        $get = static fn (string $field): mixed => $values instanceof SeoRecord
            ? $values->getAttribute($field)
            : ($values[$field] ?? null);

        return [
            'metaTitle' => $get('meta_title'),
            'metaDescription' => $get('meta_description'),
            'canonicalPath' => $get('canonical_path'),
            'robots' => [
                'index' => $get('robots_index'),
                'follow' => $get('robots_follow'),
            ],
            'openGraph' => [
                'title' => $get('og_title'),
                'description' => $get('og_description'),
                'imageId' => $get('og_image_id'),
            ],
        ];
    }
}
