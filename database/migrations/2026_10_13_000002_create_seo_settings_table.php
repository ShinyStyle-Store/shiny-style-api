<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64);
            // Empty locale represents one global value and is unique on SQLite and PostgreSQL.
            $table->string('locale', 5)->default('');
            $table->json('value_json');
            $table->timestamps();

            $table->unique(['key', 'locale'], 'seo_settings_key_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_settings');
    }
};
