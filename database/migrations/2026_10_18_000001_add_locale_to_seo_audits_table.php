<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_audits', function (Blueprint $table): void {
            $table->string('locale', 2)->default('ar');
        });

        DB::table('seo_audits')
            ->select('id', 'target_type', 'before_json', 'after_json')
            ->orderBy('id')
            ->chunkById(200, function ($audits): void {
                foreach ($audits as $audit) {
                    $locale = $this->recoverLocale($audit->target_type, $audit->before_json, $audit->after_json);

                    if ($locale !== 'ar') {
                        DB::table('seo_audits')->where('id', $audit->id)->update(['locale' => $locale]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('seo_audits', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }

    private function recoverLocale(string $targetType, ?string $beforeJson, ?string $afterJson): string
    {
        $after = $this->decodeSnapshot($afterJson);
        $before = $this->decodeSnapshot($beforeJson);

        foreach ([$after, $before] as $snapshot) {
            if (in_array($snapshot['locale'] ?? null, ['ar', 'en'], true)) {
                return $snapshot['locale'];
            }
        }

        if ($targetType === 'seo_redirect') {
            foreach ([$after, $before] as $snapshot) {
                if (preg_match('#^/(ar|en)(?:/|$)#', (string) ($snapshot['sourcePath'] ?? ''), $matches) === 1) {
                    return $matches[1];
                }
            }
        }

        // Shared changes and old snapshots without locale metadata use Arabic as the documented primary label.
        return 'ar';
    }

    /** @return array<string, mixed> */
    private function decodeSnapshot(?string $snapshot): array
    {
        if ($snapshot === null) {
            return [];
        }

        $decoded = json_decode($snapshot, true);

        return is_array($decoded) ? $decoded : [];
    }
};
