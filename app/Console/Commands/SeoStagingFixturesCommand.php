<?php

namespace App\Console\Commands;

use Database\Seeders\SeoStagingAcceptanceSeeder;
use Illuminate\Console\Command;
use RuntimeException;

final class SeoStagingFixturesCommand extends Command
{
    protected $signature = 'seo:staging-fixtures {--with-pagination : Create eligible products until at least 25 are available in both locales}';

    protected $description = 'Create opt-in SEO storefront acceptance fixtures in staging';

    public function handle(SeoStagingAcceptanceSeeder $seeder): int
    {
        try {
            $result = $seeder->seed((bool) $this->option('with-pagination'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'SEO staging fixtures ready: %d products created; %d Arabic-eligible and %d English-eligible products.',
            $result['products_created'],
            $result['products_eligible_ar'],
            $result['products_eligible_en'],
        ));

        return self::SUCCESS;
    }
}
