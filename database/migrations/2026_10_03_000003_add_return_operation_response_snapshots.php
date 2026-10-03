<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_returns', function (Blueprint $table): void {
            $table->json('decision_response_snapshot')->nullable()->after('request_fingerprint');
        });

        Schema::table('return_receipts', function (Blueprint $table): void {
            $table->json('creation_response_snapshot')->nullable()->after('request_fingerprint');
            $table->json('reversal_response_snapshot')->nullable()->after('creation_response_snapshot');
        });

        Schema::table('return_corrections', function (Blueprint $table): void {
            $table->json('response_snapshot')->nullable()->after('request_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('order_returns', function (Blueprint $table): void {
            $table->dropColumn('decision_response_snapshot');
        });

        Schema::table('return_receipts', function (Blueprint $table): void {
            $table->dropColumn(['creation_response_snapshot', 'reversal_response_snapshot']);
        });

        Schema::table('return_corrections', function (Blueprint $table): void {
            $table->dropColumn('response_snapshot');
        });
    }
};
