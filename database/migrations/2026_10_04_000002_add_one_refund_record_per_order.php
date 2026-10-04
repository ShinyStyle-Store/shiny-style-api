<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('refund_records')
            ->select('order_id')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('order_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($duplicates !== []) {
            $records = DB::table('refund_records')
                ->whereIn('order_id', $duplicates)
                ->orderBy('order_id')
                ->orderBy('id')
                ->get(['id', 'order_id']);

            throw new RuntimeException(
                'Cannot enforce one refund record per order; duplicate refund records exist: '
                .json_encode($records->map(static fn ($record): array => [
                    'id' => (int) $record->id,
                    'order_id' => (int) $record->order_id,
                ])->values()->all(), JSON_THROW_ON_ERROR),
            );
        }

        Schema::table('refund_records', function (Blueprint $table): void {
            $table->unique('order_id', 'refund_records_order_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('refund_records', function (Blueprint $table): void {
            $table->dropUnique('refund_records_order_id_unique');
        });
    }
};
