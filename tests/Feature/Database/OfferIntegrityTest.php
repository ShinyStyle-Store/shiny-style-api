<?php

namespace Tests\Feature\Database;

use App\Models\Offer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_percentage_outside_the_strict_range(): void
    {
        $this->expectException(QueryException::class);

        Offer::query()->create([
            'name' => 'Invalid percentage',
            'discount_percentage' => '100.00',
            'starts_at' => '2026-01-01T00:00:00Z',
            'ends_at' => '2026-01-02T00:00:00Z',
            'is_enabled' => false,
        ]);
    }

    public function test_database_rejects_a_non_increasing_time_window(): void
    {
        $this->expectException(QueryException::class);

        Offer::query()->create([
            'name' => 'Invalid window',
            'discount_percentage' => '10.00',
            'starts_at' => '2026-01-02T00:00:00Z',
            'ends_at' => '2026-01-01T00:00:00Z',
            'is_enabled' => false,
        ]);
    }
}
