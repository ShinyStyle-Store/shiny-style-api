<?php

namespace Tests\Feature\Services;

use App\Jobs\SendSeoRevalidation;
use App\Models\SeoSetting;
use App\Models\User;
use App\Services\SeoRevalidationService;
use App\Services\SeoSettingsService;
use App\Services\StoreContactService;
use App\Services\StoreSocialLinkService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeoRevalidationQueueTest extends TestCase
{
    use DatabaseMigrations;

    public function test_notification_is_queued_only_after_commit_and_discarded_on_rollback(): void
    {
        config([
            'seo_revalidation.enabled' => true,
            'seo_revalidation.queue_connection' => 'database',
            'seo_revalidation.queue' => 'seo-revalidation',
            'queue.connections.database.driver' => 'database',
        ]);
        Queue::fake([SendSeoRevalidation::class]);

        DB::beginTransaction();
        app(SeoRevalidationService::class)->afterCommitForTarget(
            'store',
            'store',
            'updated',
            ['ar', 'en'],
            ['store', 'sitemap'],
        );
        Queue::assertNothingPushed();
        DB::commit();

        Queue::assertPushedOn('seo-revalidation', SendSeoRevalidation::class, function (SendSeoRevalidation $job): bool {
            $event = json_decode($job->rawBody, true);

            return $event['entityType'] === 'store'
                && $event['entityId'] === 'store'
                && $event['locales'] === ['ar', 'en'];
        });

        Queue::fake([SendSeoRevalidation::class]);
        DB::beginTransaction();
        app(SeoRevalidationService::class)->afterCommitForTarget(
            'store',
            'store',
            'updated',
            ['ar', 'en'],
            ['store', 'sitemap'],
        );
        DB::rollBack();

        Queue::assertNothingPushed();
    }

    public function test_unchanged_store_setting_does_not_enqueue_revalidation(): void
    {
        config([
            'seo_revalidation.enabled' => true,
            'seo_revalidation.queue_connection' => 'database',
            'queue.connections.database.driver' => 'database',
        ]);
        $actor = User::factory()->create();
        SeoSetting::query()->create([
            'key' => 'store_name',
            'locale' => 'ar',
            'value_json' => 'متجر الاختبار',
        ]);
        Queue::fake([SendSeoRevalidation::class]);

        app(SeoSettingsService::class)->update([
            ['key' => 'store_name', 'locale' => 'ar', 'value' => 'متجر الاختبار'],
        ], $actor);

        Queue::assertNothingPushed();
    }

    public function test_changed_localized_store_setting_queues_only_that_locale_after_save(): void
    {
        config([
            'seo_revalidation.enabled' => true,
            'seo_revalidation.queue_connection' => 'database',
            'seo_revalidation.queue' => 'seo-revalidation',
            'queue.connections.database.driver' => 'database',
        ]);
        $actor = User::factory()->create();
        SeoSetting::query()->create([
            'key' => 'store_name',
            'locale' => 'en',
            'value_json' => 'Old name',
        ]);
        Queue::fake([SendSeoRevalidation::class]);

        app(SeoSettingsService::class)->update([
            ['key' => 'store_name', 'locale' => 'en', 'value' => 'New name'],
        ], $actor);

        Queue::assertPushedOn('seo-revalidation', SendSeoRevalidation::class, function (SendSeoRevalidation $job): bool {
            $event = json_decode($job->rawBody, true);

            return $event['locales'] === ['en']
                && $event['scopes'] === ['catalog', 'content', 'store', 'sitemap', 'redirects', 'metaFeed'];
        });
    }

    public function test_public_store_sources_notify_only_when_the_public_values_change(): void
    {
        config([
            'seo_revalidation.enabled' => true,
            'seo_revalidation.queue_connection' => 'database',
            'seo_revalidation.queue' => 'seo-revalidation',
            'queue.connections.database.driver' => 'database',
        ]);
        Queue::fake([SendSeoRevalidation::class]);

        $contacts = app(StoreContactService::class);
        $contacts->update(['support_email' => 'help@example.test']);
        $contacts->update(['support_email' => 'help@example.test']);

        $links = app(StoreSocialLinkService::class);
        $link = $links->create([
            'platform_code' => 'instagram',
            'url' => 'https://www.instagram.com/shiny-style',
            'is_enabled' => true,
            'sort_order' => 0,
        ]);
        $links->update($link, ['is_enabled' => false]);
        $links->update($link, ['is_enabled' => false]);
        $links->delete($link);

        Queue::assertPushedTimes(SendSeoRevalidation::class, 3);
        Queue::assertPushed(SendSeoRevalidation::class, function (SendSeoRevalidation $job): bool {
            $event = json_decode($job->rawBody, true);

            return $event['entityType'] === 'store'
                && $event['entityId'] === 'store'
                && $event['scopes'] === ['store'];
        });
    }
}
