<?php

namespace App\Services;

use App\Models\StoreContact;
use Illuminate\Support\Facades\DB;

class StoreContactService
{
    public function current(): ?StoreContact
    {
        return StoreContact::query()
            ->where('singleton_key', StoreContact::SINGLETON_KEY)
            ->first();
    }

    public function update(array $data): StoreContact
    {
        return DB::transaction(function () use ($data): StoreContact {
            $now = now();
            DB::table('store_contacts')->insertOrIgnore([
                'singleton_key' => StoreContact::SINGLETON_KEY,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $contact = StoreContact::query()
                ->where('singleton_key', StoreContact::SINGLETON_KEY)
                ->lockForUpdate()
                ->firstOrFail();
            $contact->fill($data);
            $changed = $contact->isDirty(array_keys($data));
            $contact->save();

            if ($changed) {
                app(SeoRevalidationService::class)->afterCommitForTarget(
                    'store',
                    'store',
                    'updated',
                    ['ar', 'en'],
                    ['store'],
                );
            }

            return $contact->refresh();
        });
    }
}
