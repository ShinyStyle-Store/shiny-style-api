<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->string('public_id', 26)->nullable()->unique();
        });

        DB::table('payment_attempts')->whereNull('public_id')->orderBy('id')->chunkById(100, function ($attempts): void {
            foreach ($attempts as $attempt) {
                DB::table('payment_attempts')->where('id', $attempt->id)->update(['public_id' => (string) Str::ulid()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
