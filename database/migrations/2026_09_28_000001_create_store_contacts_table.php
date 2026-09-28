<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key')->default('default')->unique();
            $table->string('support_phone', 30)->nullable();
            $table->string('support_whatsapp', 30)->nullable();
            $table->string('support_email')->nullable();
            $table->text('address_ar')->nullable();
            $table->text('address_en')->nullable();
            $table->string('google_maps_url', 2048)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_contacts');
    }
};
