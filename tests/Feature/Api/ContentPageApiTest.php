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
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.slug', 'about-us')
            ->assertJsonPath('data.0.id', null)
            ->assertJsonPath('data.0.titleAr', null)
            ->assertJsonPath('data.0.bodyEn', null)
            ->assertJsonPath('data.2.slug', 'shipping-policy')
            ->assertJsonPath('data.3.slug', 'privacy-policy')
            ->assertJsonPath('data.3.titleEn', null)
            ->assertJsonPath('data.3.bodyAr', null)
            ->assertJsonPath('data.4.slug', 'terms-conditions')
            ->assertJsonPath('data.4.id', null)
            ->assertJsonPath('data.4.titleEn', null)
            ->assertJsonPath('data.4.bodyAr', null);

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

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/content-pages/privacy-policy')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'slug' => 'privacy-policy',
                    'titleAr' => null,
                    'titleEn' => null,
                    'bodyAr' => null,
                    'bodyEn' => null,
                ],
            ]);

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/content-pages/terms-conditions')
            ->assertOk()
            ->assertJson(['data' => [
                'slug' => 'terms-conditions',
                'titleAr' => null,
                'titleEn' => null,
                'bodyAr' => null,
                'bodyEn' => null,
            ]]);
    }

    public function test_admin_content_page_responses_expose_the_persistent_id_without_changing_slug_routes(): void
    {
        $this->withToken($this->adminToken)->putJson('/api/v1/admin/content-pages/about-us', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.slug', 'about-us');

        $page = ContentPage::query()->where('slug', 'about-us')->firstOrFail();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/content-pages/about-us')
            ->assertOk()
            ->assertJsonPath('data.id', $page->getKey())
            ->assertJsonPath('data.slug', 'about-us');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/content-pages')
            ->assertOk()
            ->assertJsonPath('data.0.id', $page->getKey())
            ->assertJsonPath('data.0.slug', 'about-us');

        $this->getJson('/api/v1/content-pages/about-us')->assertOk();
    }

    public function test_public_pages_are_not_available_before_first_save_and_unknown_slugs_are_not_found(): void
    {
        $this->getJson('/api/v1/content-pages/about-us')->assertNotFound();
        $this->getJson('/api/v1/content-pages/privacy-policy')->assertNotFound();
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
        foreach (['about-us', 'returns-policy', 'shipping-policy', 'privacy-policy', 'terms-conditions'] as $slug) {
            $this->withToken($this->adminToken)
                ->putJson('/api/v1/admin/content-pages/'.$slug, $this->payload(['titleEn' => $slug]))
                ->assertOk()->assertJsonPath('data.slug', $slug)
                ->assertJsonPath('data.titleAr', 'من نحن')
                ->assertJsonPath('data.titleEn', $slug);
        }

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/content-pages')
            ->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('data.1.slug', 'returns-policy')
            ->assertJsonPath('data.1.bodyAr', 'النص العربي')
            ->assertJsonPath('data.3.slug', 'privacy-policy')
            ->assertJsonPath('data.4.slug', 'terms-conditions');
    }

    public function test_privacy_policy_is_localized_and_preserves_body_line_breaks(): void
    {
        $payload = [
            'titleAr' => 'سياسة الخصوصية',
            'titleEn' => 'Privacy Policy',
            'bodyAr' => "السطر الأول\nالسطر الثاني",
            'bodyEn' => "First line\nSecond line",
        ];

        $this->withToken($this->adminToken)
            ->putJson('/api/v1/admin/content-pages/privacy-policy', $payload)
            ->assertOk()
            ->assertJsonPath('data.slug', 'privacy-policy')
            ->assertJsonPath('data.titleAr', 'سياسة الخصوصية')
            ->assertJsonPath('data.titleEn', 'Privacy Policy')
            ->assertJsonPath('data.bodyAr', "السطر الأول\nالسطر الثاني")
            ->assertJsonPath('data.bodyEn', "First line\nSecond line");

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/content-pages/privacy-policy')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('data.title', 'سياسة الخصوصية')
            ->assertJsonPath('data.body', "السطر الأول\nالسطر الثاني");

        $this->withHeader('Accept-Language', 'en-US')
            ->getJson('/api/v1/content-pages/privacy-policy')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('data.title', 'Privacy Policy')
            ->assertJsonPath('data.body', "First line\nSecond line");

        $this->withToken($this->adminToken)
            ->putJson('/api/v1/admin/content-pages/privacy-policy', [
                ...$payload,
                'extra' => 'not allowed',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('extra');
    }

    public function test_terms_conditions_requires_all_fields_and_returns_saved_localized_content(): void
    {
        foreach (['titleAr', 'titleEn', 'bodyAr', 'bodyEn'] as $field) {
            $payload = $this->payload();
            unset($payload[$field]);

            $this->withToken($this->adminToken)
                ->putJson('/api/v1/admin/content-pages/terms-conditions', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);

            $this->app['auth']->forgetGuards();
            $this->withToken($this->adminToken)
                ->putJson('/api/v1/admin/content-pages/terms-conditions', $this->payload([$field => " \n\t "]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $payload = $this->payload([
            'titleAr' => 'الشروط والأحكام',
            'titleEn' => 'Terms and Conditions',
            'bodyAr' => 'النص العربي للشروط',
            'bodyEn' => 'English terms and conditions',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)
            ->putJson('/api/v1/admin/content-pages/terms-conditions', $payload)
            ->assertOk()
            ->assertJsonPath('data.slug', 'terms-conditions')
            ->assertJsonPath('data.titleAr', 'الشروط والأحكام')
            ->assertJsonPath('data.titleEn', 'Terms and Conditions')
            ->assertJsonPath('data.bodyAr', 'النص العربي للشروط')
            ->assertJsonPath('data.bodyEn', 'English terms and conditions');

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/content-pages/terms-conditions')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('data.title', 'الشروط والأحكام')
            ->assertJsonPath('data.body', 'النص العربي للشروط');

        $this->withHeader('Accept-Language', 'en-US')
            ->getJson('/api/v1/content-pages/terms-conditions')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('data.title', 'Terms and Conditions')
            ->assertJsonPath('data.body', 'English terms and conditions');

        $this->getJson('/api/v1/content-pages/not-a-page')->assertNotFound();
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
