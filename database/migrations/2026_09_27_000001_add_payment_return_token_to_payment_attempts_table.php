<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->string('payment_return_token_hash', 64)->nullable()->unique();
            $table->timestamp('payment_return_token_expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropUnique(['payment_return_token_hash']);
            $table->dropIndex(['payment_return_token_expires_at']);
            $table->dropColumn(['payment_return_token_hash', 'payment_return_token_expires_at']);
        });
    }
};
