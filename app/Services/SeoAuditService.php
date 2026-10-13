<?php

namespace App\Services;

use App\Models\SeoAudit;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SeoAuditService
{
    /** @param array<string, mixed>|null $before
     *  @param array<string, mixed>|null $after
     */
    public function record(
        ?int $actorId,
        string $targetType,
        int $targetId,
        string $action,
        ?array $before,
        ?array $after,
    ): SeoAudit {
        if (! in_array($action, ['create', 'update', 'delete'], true)) {
            throw new InvalidArgumentException('Unsupported SEO audit action.');
        }

        if (DB::transactionLevel() < 1) {
            throw new InvalidArgumentException('SEO audit records must be written inside the data transaction.');
        }

        return SeoAudit::query()->create([
            'actor_id' => $actorId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'action' => $action,
            'before_json' => $before,
            'after_json' => $after,
            'created_at' => now(),
        ]);
    }
}
