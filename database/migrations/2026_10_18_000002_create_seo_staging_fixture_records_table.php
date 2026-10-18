<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_staging_fixture_records', function (Blueprint $table): void {
            $table->id();
            $table->string('fixture_key', 128)->unique();
            $table->string('record_type', 32);
            $table->unsignedBigInteger('record_id');
            $table->string('record_fingerprint', 64)->nullable();
            $table->timestamps();

            $table->index(['record_type', 'record_id'], 'seo_staging_fixture_records_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_staging_fixture_records');
    }
};
