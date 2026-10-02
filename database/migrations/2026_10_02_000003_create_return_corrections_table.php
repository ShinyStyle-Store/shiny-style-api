<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_receipt_id')->constrained('return_receipts')->restrictOnDelete();
            $table->unsignedInteger('expected_revision');
            $table->unsignedInteger('applied_revision');
            $table->text('explanation');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('idempotency_key');
            $table->string('request_fingerprint', 64);
            $table->timestamps();

            $table->unique(['return_receipt_id', 'idempotency_key']);
            $table->unique(['return_receipt_id', 'applied_revision']);
            $table->index(['return_receipt_id', 'applied_revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_corrections');
    }
};
