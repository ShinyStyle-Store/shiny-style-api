<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('method', 32);
            $table->string('status', 32);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('merchant_reference', 64)->unique();
            $table->string('idempotency_key', 128)->unique();
            $table->string('request_fingerprint', 64);
            $table->string('provider_intention_id', 128)->nullable();
            $table->string('provider_order_id', 128)->nullable();
            $table->string('provider_transaction_id', 128)->nullable()->unique();
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->string('failure_code', 128)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['provider', 'provider_intention_id']);
            $table->index(['provider', 'provider_order_id']);
            $table->index(['provider', 'integration_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
