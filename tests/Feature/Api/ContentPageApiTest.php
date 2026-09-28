<?php

namespace Tests\Feature\Api;

use App\Models\AdminMembership;
use App\Models\ContentPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentPageApiTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->getKey()]);
        $this->adminToken = $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }

    public function test_admin_routes_require_admin_authorization(): void
    {
        $this->getJson('/api/v1/admin/content-pages')->assertUnauthorized();
        $this->getJson('/api/v1/admin/content-pages/about-us')->assertUnauthorized();
        $this->putJson('/api/v1/admin/content-pages/about-us', $this->payload())->assertUnauthorized();

        $customer = User::factory()->create();
        $token = $customer->createToken('customer', ['customer-access'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/admin/content-pages')->assertForbidden();
        $this->withToken($token)->putJson('/api/v1/admin/content-pages/about-us', $this->payload())
            ->assertForbidden();
    }

    public function test_admin_list_and_show_return_stable_unconfigured_pages(): void
    {
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/content-pages')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.slug', 'about-us')
            ->assertJsonPath('data.0.titleAr', null)
            ->assertJsonPath('data.0.bodyEn', null)
            ->assertJsonPath('data.2.slug', 'shipping-policy');

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/content-pages/returns-policy')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'slug' => 'returns-policy',
                    'titleAr' => null,
                    'titleEn' => null,
                    'bodyAr' => null,
                    'bodyEn' => null,
                ],
            ]);
    }

    public function test_public_pages_are_not_available_before_first_save_and_unknown_slugs_are_not_found(): void
    {
        $this->getJson('/api/v1/content-pages/about-us')->assertNotFound();
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/content-pages/not-a-page')->assertNotFound();
        $this->getJson('/api/v1/content-pages/not-a-page')->assertNotFound();
    }

    public function test_put_requires_all_non_blank_bilingual_fields_and_rejects_unknown_fields(): void
    {
        $payload = $this->payload();
        unset($payload['bodyEn']);
        $this->withToken($this->adminToken)->putJson('/api/v1/admin/content-pages/about-us', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('bodyEn');

        $blank = $this->payload([
            'titleAr' => " \n\t ",
            'bodyEn' => "\n  \n",
            'title_ar' => 'Legacy field',
            'extra' => 'not allowed',
        ]);
        $this->withToken($this->adminToken)->putJson('/api/v1/admin/content-pages/about-us', $blank)
            ->assertUnprocessable()->assertJsonValidationErrors(['titleAr', 'bodyEn', 'title_ar', 'extra']);

        $this->withToken($this->adminToken)->putJson('/api/v1/admin/content-pages/about-us', [
            ...$this->payload(),
            'slug' => 'shipping-policy',
        ])->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_put_saves_both_languages_atomically_and_makes_content_public_immediately(): void
    {
        $payload = $this->payload([
            'bodyAr' => "السطر الأول\nالسطر الثاني",
            'bodyEn' => "First line\nSecond line",
        ]);

        $this->withToken($this->adminToken)
            ->putJson('/api/v1/admin/content-pages/about-us', $payload)
            ->assertOk()
            ->assertJsonPath('data.slug', 'about-us')
            ->assertJsonPath('data.titleAr', 'من نحن')
            ->assertJsonPath('data.bodyEn', "First line\nSecond line");

        $this->assertDatabaseHas('content_pages', [
            'slug' => 'about-us',
            'title_ar' => 'من نحن',
            'title_en' => 'About us',
            'body_ar' => "السطر الأول\nالسطر الثاني",
            'body_en' => "First line\nSecond line",
        ]);

        $response = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/content-pages/about-us')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('data.title', 'من نحن')
            ->assertJsonPath('data.body', "السطر الأول\nالسطر الثاني");

        $varyTokens = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $response->headers->get('Vary')),
        )));
        $this->assertContains('Accept-Language', $varyTokens);

        $this->withHeader('Accept-Language', 'en-US')
            ->getJson('/api/v1/content-pages/about-us')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('data.title', 'About us')
            ->assertJsonPath('data.body', "First line\nSecond line");

        $this->assertSame(1, ContentPage::query()->where('slug', 'about-us')->count());
    }

    public function test_each_supported_slug_can_be_saved_and_admin_returns_both_languages(): void
    {
        foreach (['about-us', 'returns-policy', 'shipping-policy'] as $slug) {
            $this->withToken($this->adminToken)
                ->putJson('/api/v1/admin/content-pages/'.$slug, $this->payload(['titleEn' => $slug]))
                ->assertOk()->assertJsonPath('data.slug', $slug)
                ->assertJsonPath('data.titleAr', 'من نحن')
                ->assertJsonPath('data.titleEn', $slug);
        }

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/content-pages')
            ->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.1.slug', 'returns-policy')
            ->assertJsonPath('data.1.bodyAr', 'النص العربي');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'titleAr' => 'من نحن',
            'titleEn' => 'About us',
            'bodyAr' => 'النص العربي',
            'bodyEn' => 'English text',
        ], $overrides);
    }
}
