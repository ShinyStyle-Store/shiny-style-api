<?php

namespace App\Console\Commands;

use App\Models\ProductMedia;
use App\Services\ProductMediaMigrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MigrateProductMedia extends Command
{
    protected $signature = 'media:migrate-product-media
        {--dry-run : Inspect records and conflicts without writing to the database or storage}
        {--batch= : Number of records to process per batch}';

    protected $description = 'Safely migrate legacy ProductMedia files into unified media storage';

    public function handle(ProductMediaMigrationService $migration): int
    {
        $batchSize = $this->batchSize();
        if ($batchSize === null) {
            $this->error('The batch size must be an integer between 1 and 1000.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $conflicts = $migration->primaryConflicts();
        $conflictsById = collect($conflicts)->keyBy('legacy_id');
        $counts = [
            'scanned' => 0,
            'migrated' => 0,
            'already_migrated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'would_migrate' => 0,
        ];

        if ($conflicts !== [] && ! $dryRun) {
            foreach ($conflicts as $conflict) {
                $this->failure($conflict['legacy_id'], $conflict['reason']);
            }
            $counts['scanned'] = ProductMedia::withTrashed()->count();
            $counts['failed'] = count($conflicts);
            $this->summary($counts, false);

            return self::FAILURE;
        }

        if (! $dryRun) {
            $storageFailure = $migration->assertStorageReady();
            if ($storageFailure !== null) {
                $this->error($storageFailure.'. Configure a durable media disk before production migration.');
                $counts['scanned'] = ProductMedia::withTrashed()->count();
                $counts['failed'] = 1;
                $this->summary($counts, false);

                return self::FAILURE;
            }
        }

        ProductMedia::withTrashed()
            ->orderBy('id')
            ->chunkById($batchSize, function ($records) use ($migration, $dryRun, $conflictsById, &$counts): void {
                foreach ($records as $record) {
                    $counts['scanned']++;
                    $conflict = $conflictsById->get((int) $record->getKey());
                    if ($conflict !== null) {
                        $counts['failed']++;
                        $this->failure($record->getKey(), $conflict['reason'], ! $dryRun);

                        continue;
                    }

                    try {
                        $result = $dryRun
                            ? $migration->inspect($record)
                            : $migration->migrate($record);
                    } catch (Throwable) {
                        $result = ['status' => 'failed', 'reason' => 'migration record could not be processed'];
                    }

                    match ($result['status']) {
                        'migrated' => $counts['migrated']++,
                        'already_migrated' => $counts['already_migrated']++,
                        'skipped' => $counts['skipped']++,
                        'candidate' => $counts['would_migrate']++,
                        default => $counts['failed']++,
                    };

                    if ($result['status'] === 'failed') {
                        $this->failure($record->getKey(), (string) $result['reason'], ! $dryRun);
                    }
                }
            });

        $this->summary($counts, $dryRun);

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function batchSize(): ?int
    {
        $value = $this->option('batch') ?? config('media.legacy_migration.batch_size', 100);
        $batchSize = filter_var($value, FILTER_VALIDATE_INT);
        if ($batchSize === false || $batchSize < 1 || $batchSize > 1000) {
            return null;
        }

        return $batchSize;
    }

    private function failure(int|string $legacyId, string $reason, bool $log = true): void
    {
        if ($log) {
            Log::warning('Legacy ProductMedia migration failed.', [
                'legacy_product_media_id' => $legacyId,
                'reason' => $reason,
            ]);
        }
        $this->error("Legacy ProductMedia #{$legacyId}: {$reason}.");
    }

    /** @param array<string, int> $counts */
    private function summary(array $counts, bool $dryRun): void
    {
        $this->newLine();
        $this->table(['Result', 'Count'], [
            ['scanned', $counts['scanned']],
            ['migrated', $counts['migrated']],
            ['already migrated', $counts['already_migrated']],
            ['skipped', $counts['skipped']],
            ['failed', $counts['failed']],
            ...($dryRun ? [['would migrate', $counts['would_migrate']]] : []),
        ]);
    }
}
