<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            // Nullable because the identifier is derived from the inserted numeric ID.
            $table->string('order_number')->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('customer_name');
            $table->string('customer_phone');
            $table->string('alternate_phone')->nullable();
            $table->string('customer_email')->nullable();

            $table->foreignId('shipping_area_id')->constrained('shipping_areas')->restrictOnDelete();
            $table->string('shipping_area_code');
            $table->string('shipping_area_name_ar');
            $table->string('shipping_area_name_en');
            $table->text('shipping_address');
            $table->string('shipping_landmark')->nullable();
            $table->text('customer_note')->nullable();

            $table->string('currency', 3)->default('EGP');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('shipping_fee', 10, 2);
            $table->decimal('total', 10, 2);

            $table->string('status')->default('pending_confirmation');
            $table->string('contact_status')->default('not_contacted');
            $table->string('payment_method')->default('cash_on_delivery');
            $table->string('payment_status')->default('unpaid');

            $table->string('cancellation_reason')->nullable();
            $table->text('cancellation_note')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('contact_note')->nullable();
            $table->timestamp('last_contacted_at')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['contact_status', 'created_at']);
            $table->index('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
