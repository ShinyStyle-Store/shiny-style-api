<?php

namespace App\Console\Commands;

use App\Services\SeoStagingFixtureCleanupService;
use Illuminate\Console\Command;
use RuntimeException;

final class CleanSeoStagingFixturesCommand extends Command
{
    protected $signature = 'seo:staging-fixtures:clean';

    protected $description = 'Remove only records owned by the SEO staging acceptance fixtures';

    public function handle(SeoStagingFixtureCleanupService $cleanup): int
    {
        try {
            $result = $cleanup->clean();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'SEO staging fixture cleanup complete: %d records removed; %d records retained for safety.',
            $result['deleted'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
