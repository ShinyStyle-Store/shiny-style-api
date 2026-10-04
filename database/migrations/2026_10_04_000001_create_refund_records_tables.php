<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('public_id', 26)->unique();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('transfer_method', 32);
            $table->timestamp('transferred_at');
            $table->string('transaction_reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->string('request_fingerprint', 64);
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });

        Schema::create('refund_record_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_record_id')->constrained('refund_records')->restrictOnDelete();
            $table->unsignedInteger('expected_revision');
            $table->unsignedInteger('applied_revision');
            $table->text('reason');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('idempotency_key');
            $table->string('request_fingerprint', 64);
            $table->json('previous_snapshot');
            $table->json('new_snapshot');
            $table->foreignId('previous_media_attachment_id')->nullable()->constrained('media_attachments')->restrictOnDelete();
            $table->foreignId('new_media_attachment_id')->nullable()->constrained('media_attachments')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['refund_record_id', 'idempotency_key']);
            $table->unique(['refund_record_id', 'applied_revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_record_corrections');
        Schema::dropIfExists('refund_records');
    }
};
