<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paymob_payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('payment_attempt_id')->constrained('payment_attempts')->restrictOnDelete();
            $table->string('provider_transaction_id', 128)->unique();
            $table->string('provider_order_id', 128)->nullable();
            $table->string('merchant_reference', 64)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->boolean('provider_is_live')->nullable();
            $table->string('status', 32);
            $table->string('review_code', 128)->nullable();
            $table->boolean('success')->nullable();
            $table->boolean('pending')->nullable();
            $table->boolean('error_occurred')->nullable();
            $table->boolean('is_refunded')->nullable();
            $table->unsignedBigInteger('refunded_amount_minor')->nullable();
            $table->boolean('is_voided')->nullable();
            $table->string('source_type', 32)->nullable();
            $table->string('source_subtype', 64)->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['payment_attempt_id', 'status']);
        });

        DB::table('payment_attempts')
            ->whereNotNull('provider_transaction_id')
            ->orderBy('id')
            ->chunkById(500, function ($attempts): void {
                foreach ($attempts as $attempt) {
                    DB::table('paymob_payment_transactions')->insert([
                        'order_id' => $attempt->order_id,
                        'payment_attempt_id' => $attempt->id,
                        'provider_transaction_id' => $attempt->provider_transaction_id,
                        'provider_order_id' => $attempt->provider_order_id,
                        'merchant_reference' => $attempt->merchant_reference,
                        'amount_minor' => $attempt->amount_minor,
                        'currency' => $attempt->currency,
                        'integration_id' => $attempt->integration_id,
                        'provider_is_live' => $attempt->provider_is_live,
                        'status' => $attempt->status,
                        'review_code' => $attempt->status === 'requires_review' ? $attempt->failure_code : null,
                        'success' => $attempt->status === 'paid' ? true : null,
                        'pending' => $attempt->status === 'pending' ? true : null,
                        'error_occurred' => $attempt->status === 'failed' ? true : null,
                        'is_refunded' => null,
                        'refunded_amount_minor' => null,
                        'is_voided' => null,
                        'source_type' => null,
                        'source_subtype' => null,
                        'provider_created_at' => null,
                        'received_at' => $attempt->updated_at ?? now(),
                        'created_at' => $attempt->created_at ?? now(),
                        'updated_at' => $attempt->updated_at ?? now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('paymob_payment_transactions');
    }
};
