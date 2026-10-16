<?php

namespace App\Services;

use App\Models\SocialLink;
use Illuminate\Support\Facades\DB;

final class StoreSocialLinkService
{
    /** @param array<string, mixed> $data */
    public function create(array $data): SocialLink
    {
        return DB::transaction(function () use ($data): SocialLink {
            $link = SocialLink::query()->create($data);
            if ($link->is_enabled) {
                $this->notifyStoreChanged();
            }

            return $link;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(SocialLink $link, array $data): SocialLink
    {
        return DB::transaction(function () use ($link, $data): SocialLink {
            $locked = SocialLink::query()->whereKey($link->getKey())->lockForUpdate()->firstOrFail();
            $wasPublic = (bool) $locked->is_enabled;
            $locked->fill($data);
            $changed = $locked->isDirty();
            $locked->save();

            if ($changed && ($wasPublic || $locked->is_enabled)) {
                $this->notifyStoreChanged();
            }

            return $locked->refresh();
        });
    }

    public function delete(SocialLink $link): void
    {
        DB::transaction(function () use ($link): void {
            $locked = SocialLink::query()->whereKey($link->getKey())->lockForUpdate()->firstOrFail();
            $wasPublic = (bool) $locked->is_enabled;
            $locked->delete();

            if ($wasPublic) {
                $this->notifyStoreChanged();
            }
        });
    }

    private function notifyStoreChanged(): void
    {
        app(SeoRevalidationService::class)->afterCommitForTarget(
            'store',
            'store',
            'updated',
            ['ar', 'en'],
            ['store'],
        );
    }
}
