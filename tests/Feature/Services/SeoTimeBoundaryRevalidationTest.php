<?php

namespace Tests\Feature\Services;

use App\Jobs\SendSeoRevalidation;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Product;
use App\Models\SellableItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeoTimeBoundaryRevalidationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_downtime_boundaries_are_enqueued_before_the_checkpoint_advances(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00:00 UTC'));
        config([
            'seo_revalidation.enabled' => true,
            'seo_revalidation.queue_connection' => 'database',
            'seo_revalidation.queue' => 'seo-revalidation',
            'queue.connections.database.driver' => 'database',
        ]);
        DB::table('seo_revalidation_checkpoints')->where('checkpoint_key', 'time-boundaries')->update([
            'checkpoint_at' => '2026-10-16 09:00:00',
        ]);
        $product = $this->publicProduct();
        $this->publicProduct('scheduled-publication-product', '2026-10-16 09:45:00');
        $offer = Offer::query()->create([
            'name' => 'Scheduled SEO offer',
            'discount_percentage' => 10,
            'starts_at' => '2026-10-16 09:30:00',
            'ends_at' => '2026-10-16 09:50:00',
            'is_enabled' => true,
        ]);
        $offer->products()->attach($product);
        Queue::fake([SendSeoRevalidation::class]);

        $this->artisan('seo:revalidation-time-boundaries')->assertExitCode(0);

        Queue::assertPushedOn('seo-revalidation', SendSeoRevalidation::class, function (SendSeoRevalidation $job): bool {
            $event = json_decode($job->rawBody, true);

            return $event['reason'] === 'time_boundary'
                && $event['occurredAt'] === '2026-10-16T09:50:00Z'
                && $event['scopes'] === ['catalog', 'sitemap', 'metaFeed'];
        });
        $this->assertSame(
            '2026-10-16 10:00:00',
            DB::table('seo_revalidation_checkpoints')->where('checkpoint_key', 'time-boundaries')->value('checkpoint_at'),
        );

        $this->artisan('seo:revalidation-time-boundaries')->assertExitCode(0);
        Queue::assertPushedTimes(SendSeoRevalidation::class, 1);
    }

    public function test_failed_enqueue_does_not_advance_the_time_checkpoint(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00:00 UTC'));
        config([
            'seo_revalidation.enabled' => true,
            'seo_revalidation.queue_connection' => 'sync',
            'queue.connections.sync.driver' => 'sync',
        ]);
        DB::table('seo_revalidation_checkpoints')->where('checkpoint_key', 'time-boundaries')->update([
            'checkpoint_at' => '2026-10-16 09:00:00',
        ]);
        $product = $this->publicProduct();
        $offer = Offer::query()->create([
            'name' => 'Scheduled SEO offer',
            'discount_percentage' => 10,
            'starts_at' => '2026-10-16 09:30:00',
            'ends_at' => '2026-10-16 10:30:00',
            'is_enabled' => true,
        ]);
        $offer->products()->attach($product);

        $this->artisan('seo:revalidation-time-boundaries')->assertExitCode(1);

        $this->assertSame(
            '2026-10-16 09:00:00',
            DB::table('seo_revalidation_checkpoints')->where('checkpoint_key', 'time-boundaries')->value('checkpoint_at'),
        );
    }

    public function test_scheduled_publication_is_detected_after_scheduler_downtime(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00:00 UTC'));
        config([
            'seo_revalidation.enabled' => true,
            'seo_revalidation.queue_connection' => 'database',
            'seo_revalidation.queue' => 'seo-revalidation',
            'queue.connections.database.driver' => 'database',
        ]);
        DB::table('seo_revalidation_checkpoints')->where('checkpoint_key', 'time-boundaries')->update([
            'checkpoint_at' => '2026-10-16 09:00:00',
        ]);
        $this->publicProduct('scheduled-publication-product', '2026-10-16 09:45:00');
        Queue::fake([SendSeoRevalidation::class]);

        $this->artisan('seo:revalidation-time-boundaries')->assertExitCode(0);

        Queue::assertPushed(SendSeoRevalidation::class, function (SendSeoRevalidation $job): bool {
            $event = json_decode($job->rawBody, true);

            return $event['reason'] === 'time_boundary'
                && $event['occurredAt'] === '2026-10-16T09:45:00Z';
        });
    }

    private function publicProduct(string $slug = 'time-boundary-product', string $publishedAt = '2026-10-16 08:00:00'): Product
    {
        static $categoryCounter = 0;
        $categoryCounter++;
        $category = Category::query()->create([
            'slug' => 'time-boundary-category-'.$categoryCounter,
            'name_ar' => 'قسم العروض',
            'name_en' => 'Offer category',
            'status' => 'active',
        ]);
        $product = Product::query()->create([
            'slug' => $slug,
            'name_ar' => 'منتج العرض',
            'name_en' => 'Offer product',
            'description_ar' => 'وصف عربي',
            'description_en' => 'English description',
            'status' => 'active',
            'published_at' => $publishedAt,
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);
        SellableItem::query()->create([
            'product_id' => $product->getKey(),
            'sku' => 'SEO-TIME-BOUNDARY-'.$product->getKey(),
            'price' => 100,
            'stock_quantity' => 10,
            'reserved_quantity' => 0,
            'status' => 'active',
            'is_default' => true,
        ]);

        return $product;
    }
}
