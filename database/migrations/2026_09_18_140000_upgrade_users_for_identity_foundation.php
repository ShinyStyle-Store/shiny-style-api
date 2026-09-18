<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'public_id')) {
                $table->string('public_id', 26)->nullable();
            }
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->nullable();
            }
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (! Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable();
            }
        });

        // Nullability is temporary: existing rows must be backfilled before the unique non-null constraint.
        Schema::getConnection()->table('users')->whereNull('public_id')->orderBy('id')->eachById(
            function (object $user): void {
                Schema::getConnection()->table('users')->where('id', $user->id)->update([
                    'public_id' => (string) Str::ulid(),
                ]);
            }
        );

        Schema::getConnection()->table('users')->whereNull('is_active')->update([
            'is_active' => true,
        ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('public_id');
            $table->unique('phone');
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->string('public_id', 26)->nullable(false)->change();
            $table->boolean('is_active')->default(true)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_public_id_unique');
            $table->dropUnique('users_phone_unique');
            $table->dropColumn(['public_id', 'phone', 'is_active', 'phone_verified_at']);
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
