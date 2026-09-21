<?php

namespace App\Console\Commands;

use App\Services\ProductMediaMigrationVerificationService;
use Illuminate\Console\Command;

final class VerifyProductMediaMigration extends Command
{
    protected $signature = 'media:verify-product-media-migration';

    protected $description = 'Verify every active legacy ProductMedia row is mapped to valid unified media';

    public function handle(ProductMediaMigrationVerificationService $verification): int
    {
        $result = $verification->verify();
        foreach ($result['issues'] as $issue) {
            $record = $issue['legacy_id'] === null ? 'Legacy ProductMedia table' : 'Legacy ProductMedia #'.$issue['legacy_id'];
            $this->error($record.': '.$issue['reason'].'.');
        }

        $this->table(['Result', 'Count'], [
            ['scanned', $result['scanned']],
            ['verified', $result['verified']],
            ['failed', count($result['issues'])],
        ]);

        return $result['issues'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
