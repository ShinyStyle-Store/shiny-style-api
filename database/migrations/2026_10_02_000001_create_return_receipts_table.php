<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->timestamp('request_received_at')->nullable();
            $table->timestamp('received_at');
            $table->string('default_reason')->nullable();
            $table->text('note')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('idempotency_key');
            $table->string('request_fingerprint', 64);
            $table->timestamps();

            $table->unique(['order_id', 'idempotency_key']);
            $table->index(['order_id', 'received_at']);
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_receipts');
    }
};
