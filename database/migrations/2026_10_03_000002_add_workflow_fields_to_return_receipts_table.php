<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_receipts', function (Blueprint $table): void {
            $table->foreignId('order_return_id')->nullable()->after('order_id')
                ->constrained('order_returns')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('revision');
            $table->foreignId('reversed_by_user_id')->nullable()->after('reversed_at')
                ->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable()->after('reversed_by_user_id');
            $table->uuid('reversal_idempotency_key')->nullable()->after('reversal_reason')->unique();
            $table->string('reversal_request_fingerprint', 64)->nullable()->after('reversal_idempotency_key');
            $table->index(['order_return_id', 'reversed_at']);
        });

        // Both supported databases support partial unique indexes, while the
        // application check remains the portable fallback for test databases.
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX return_receipts_one_effective_workflow_receipt '
                .'ON return_receipts (order_return_id) '
                .'WHERE order_return_id IS NOT NULL AND reversed_at IS NULL',
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS return_receipts_one_effective_workflow_receipt');
        }

        Schema::table('return_receipts', function (Blueprint $table): void {
            $table->dropForeign(['order_return_id']);
            $table->dropForeign(['reversed_by_user_id']);
            $table->dropUnique(['reversal_idempotency_key']);
            $table->dropIndex(['order_return_id', 'reversed_at']);
            $table->dropColumn([
                'order_return_id', 'reversed_at', 'reversed_by_user_id', 'reversal_reason',
                'reversal_idempotency_key', 'reversal_request_fingerprint',
            ]);
        });
    }
};
