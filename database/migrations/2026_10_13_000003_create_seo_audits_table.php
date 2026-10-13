<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_type', 64);
            $table->unsignedBigInteger('target_id');
            $table->string('action', 16);
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->timestamp('created_at');

            $table->index(['target_type', 'target_id', 'created_at'], 'seo_audits_target_created_index');
            $table->index(['actor_id', 'created_at'], 'seo_audits_actor_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_audits');
    }
};
