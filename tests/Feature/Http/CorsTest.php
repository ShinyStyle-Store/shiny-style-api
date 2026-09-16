<?php

namespace Tests\Feature\Http;

use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorsTest extends TestCase
{
    use RefreshDatabase;

    private const ALLOWED_ORIGIN = 'http://localhost:3000';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cors.allowed_origins' => [self::ALLOWED_ORIGIN],
            'cors.allowed_origins_patterns' => [],
        ]);

        $this->seed(ProductCatalogSeeder::class);
    }

    public function test_allowed_origin_preflight_returns_configured_methods_and_headers(): void
    {
        $response = $this->withHeaders([
            'Origin' => self::ALLOWED_ORIGIN,
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Accept, Authorization, Accept-Language, Idempotency-Key',
        ])->options('/api/v1/products');

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::ALLOWED_ORIGIN)
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->assertHeader('Access-Control-Allow-Headers', 'Accept, Authorization, Content-Type, Accept-Language, X-Requested-With, Idempotency-Key');
    }

    public function test_allowed_origin_get_returns_cors_headers_and_exposes_content_language(): void
    {
        $response = $this->withHeaders([
            'Origin' => self::ALLOWED_ORIGIN,
            'Accept-Language' => 'ar',
        ])
            ->getJson('/api/v1/products');

        $response->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::ALLOWED_ORIGIN)
            ->assertHeader('Access-Control-Expose-Headers', 'Content-Language')
            ->assertHeader('Content-Language', 'ar')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_disallowed_origin_does_not_receive_cors_access(): void
    {
        $response = $this->withHeader('Origin', 'https://malicious.example')
            ->getJson('/api/v1/products');

        $response->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Credentials');

        $allowOrigin = $response->headers->get('Access-Control-Allow-Origin');

        $this->assertNotSame('https://malicious.example', $allowOrigin);
        $this->assertNotSame('*', $allowOrigin);
    }

    public function test_cors_is_scoped_to_api_paths(): void
    {
        $response = $this->withHeader('Origin', self::ALLOWED_ORIGIN)
            ->get('/');

        $response->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_wildcard_origin_is_not_returned(): void
    {
        config(['cors.allowed_origins' => []]);

        $response = $this->withHeader('Origin', self::ALLOWED_ORIGIN)
            ->getJson('/api/v1/products');

        $response->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
