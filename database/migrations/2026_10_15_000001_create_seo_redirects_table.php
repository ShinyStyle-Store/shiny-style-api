<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('domain_scope', 255);
            $table->string('source_path', 2048);
            $table->char('source_hash', 64);
            $table->string('destination_path', 2048);
            $table->unsignedSmallInteger('status_code');
            $table->boolean('is_active')->default(true);
            $table->string('reason', 40);
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->unique(['domain_scope', 'source_hash'], 'seo_redirect_domain_hash_uq');
            $table->index(['entity_type', 'entity_id'], 'seo_redirect_entity_ix');
            $table->foreign('created_by', 'seo_redirect_creator_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_redirects');
    }
};
