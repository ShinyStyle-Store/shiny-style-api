<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_revalidation_checkpoints', function (Blueprint $table): void {
            $table->string('checkpoint_key', 40)->primary();
            $table->timestampTz('checkpoint_at');
            $table->timestampsTz();
        });

        $now = now('UTC');
        DB::table('seo_revalidation_checkpoints')->insert([
            'checkpoint_key' => 'time-boundaries',
            'checkpoint_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_revalidation_checkpoints');
    }
};
