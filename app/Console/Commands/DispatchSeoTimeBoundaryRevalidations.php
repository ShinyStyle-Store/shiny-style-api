<?php

namespace App\Console\Commands;

use App\Models\Offer;
use App\Models\Product;
use App\Services\CategoryHierarchyService;
use App\Services\SeoRevalidationPayloadFactory;
use App\Services\SeoRevalidationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class DispatchSeoTimeBoundaryRevalidations extends Command
{
    protected $signature = 'seo:revalidation-time-boundaries';

    protected $description = 'Dispatch SEO invalidation after scheduled publishing and offer boundaries.';

    public function handle(
        CategoryHierarchyService $categories,
        SeoRevalidationPayloadFactory $payloads,
        SeoRevalidationService $revalidation,
    ): int {
        if (! config('seo_revalidation.enabled', false)) {
            return self::SUCCESS;
        }

        $checkpointValue = DB::table('seo_revalidation_checkpoints')
            ->where('checkpoint_key', 'time-boundaries')
            ->value('checkpoint_at');
        if (! is_string($checkpointValue)) {
            $this->error('SEO time-boundary checkpoint is missing; no checkpoint was advanced.');

            return self::FAILURE;
        }

        $checkpoint = CarbonImmutable::parse($checkpointValue, 'UTC')->utc();
        $now = CarbonImmutable::now('UTC');
        if ($checkpoint->greaterThanOrEqualTo($now)) {
            return self::SUCCESS;
        }

        $latestBoundary = $this->latestPublicBoundary(
            $checkpoint,
            $now,
            $categories->visibilitySnapshot()->visibleIds(),
        );

        if ($latestBoundary !== null) {
            $payload = $payloads->make(
                'store',
                'store',
                'time_boundary',
                ['ar', 'en'],
                ['catalog', 'sitemap', 'metaFeed'],
                occurredAt: $latestBoundary,
            );

            if (! $revalidation->enqueue($payload)) {
                $this->error('SEO time-boundary notification could not be saved to the queue.');

                return self::FAILURE;
            }
        }

        DB::table('seo_revalidation_checkpoints')
            ->where('checkpoint_key', 'time-boundaries')
            ->where('checkpoint_at', $checkpointValue)
            ->update(['checkpoint_at' => $now->toDateTimeString(), 'updated_at' => $now->toDateTimeString()]);

        Log::info('SEO time-boundary checkpoint advanced.', [
            'checkpoint_at' => $now->toIso8601ZuluString(),
            'boundary_found' => $latestBoundary !== null,
        ]);

        return self::SUCCESS;
    }

    /** @param list<int> $visibleCategoryIds */
    private function latestPublicBoundary(
        CarbonImmutable $checkpoint,
        CarbonImmutable $now,
        array $visibleCategoryIds,
    ): ?CarbonImmutable {
        $latest = null;
        $queryRange = [$checkpoint->toDateTimeString(), $now->toDateTimeString()];

        foreach (['starts_at', 'ends_at'] as $column) {
            $boundary = Offer::query()
                ->enabled()
                ->where($column, '>', $queryRange[0])
                ->where($column, '<=', $queryRange[1])
                ->whereHas('products', fn (EloquentBuilder $query): EloquentBuilder =>
                    $query->visible($visibleCategoryIds))
                ->max($column);
            $latest = $this->laterTimestamp($latest, $boundary);
        }

        $publishedAt = Product::query()
            ->visible($visibleCategoryIds)
            ->where('published_at', '>', $queryRange[0])
            ->where('published_at', '<=', $queryRange[1])
            ->max('published_at');

        return $this->laterTimestamp($latest, $publishedAt);
    }

    private function laterTimestamp(?CarbonImmutable $current, mixed $candidate): ?CarbonImmutable
    {
        if (! is_string($candidate)) {
            return $current;
        }

        $timestamp = CarbonImmutable::parse($candidate, 'UTC')->utc();

        return $current === null || $timestamp->greaterThan($current) ? $timestamp : $current;
    }
}
