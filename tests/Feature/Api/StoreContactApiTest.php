<?php

namespace Tests\Feature\Api;

use App\Models\AdminMembership;
use App\Models\StoreContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreContactApiTest extends TestCase
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

    public function test_public_contact_is_stable_before_first_configuration(): void
    {
        $this->getJson('/api/v1/store/contact')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'support_phone' => null,
                    'support_whatsapp' => null,
                    'support_email' => null,
                    'address' => null,
                    'google_maps_url' => null,
                ],
            ])
            ->assertJsonMissing(['address_ar' => null, 'address_en' => null]);
    }

    public function test_admin_routes_require_admin_authorization(): void
    {
        $this->getJson('/api/v1/admin/store/contact')->assertUnauthorized();
        $this->putJson('/api/v1/admin/store/contact', $this->payload())->assertUnauthorized();

        $customer = User::factory()->create();
        $token = $customer->createToken('customer', ['customer-access'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/admin/store/contact')->assertForbidden();
        $this->withToken($token)->putJson('/api/v1/admin/store/contact', $this->payload())->assertForbidden();
    }

    public function test_put_requires_the_full_allowlisted_payload_and_purpose_specific_values(): void
    {
        $this->withToken($this->adminToken)
            ->putJson('/api/v1/admin/store/contact', [
                ...$this->payload(),
                'support_phone' => 'not-a-phone',
                'support_email' => 'invalid-email',
                'google_maps_url' => 'http://maps.google.com/example',
                'secret' => 'not-allowed',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['support_phone', 'support_email', 'google_maps_url', 'secret']);

        $partial = $this->payload();
        unset($partial['address_en']);
        $this->withToken($this->adminToken)->putJson('/api/v1/admin/store/contact', $partial)
            ->assertUnprocessable()->assertJsonValidationErrors('address_en');
    }

    public function test_google_maps_url_accepts_only_the_supported_https_hosts(): void
    {
        foreach (['https://maps.google.com/?q=Shiny+Style', 'https://maps.app.goo.gl/abc123'] as $url) {
            $this->withToken($this->adminToken)
                ->putJson('/api/v1/admin/store/contact', $this->payload(['google_maps_url' => $url]))
                ->assertOk();
        }

        foreach ([
            'http://maps.google.com/?q=Shiny+Style',
            'https://example.com/maps',
            'https://maps.google.com.evil.example/maps',
            'https://user:password@maps.google.com/maps',
            'https://maps.google.com:8443/maps',
        ] as $url) {
            $this->withToken($this->adminToken)
                ->putJson('/api/v1/admin/store/contact', $this->payload(['google_maps_url' => $url]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('google_maps_url');
        }
    }

    public function test_first_put_and_repeated_put_update_one_singleton_row(): void
    {
        $this->withToken($this->adminToken)
            ->putJson('/api/v1/admin/store/contact', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.address_ar', 'شارع التحرير');

        $this->withToken($this->adminToken)
            ->putJson('/api/v1/admin/store/contact', $this->payload([
                'support_phone' => '+201111111111',
                'support_whatsapp' => '+201222222222',
                'address_en' => 'Updated Tahrir Street',
            ]))
            ->assertOk()
            ->assertJsonPath('data.support_phone', '+201111111111')
            ->assertJsonPath('data.support_whatsapp', '+201222222222')
            ->assertJsonPath('data.address_en', 'Updated Tahrir Street');

        $this->assertSame(1, StoreContact::query()->count());
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/store/contact')
            ->assertOk()->assertJsonPath('data.address_ar', 'شارع التحرير');
    }

    public function test_public_contact_localizes_only_the_address_and_preserves_distinct_numbers(): void
    {
        $this->withToken($this->adminToken)->putJson('/api/v1/admin/store/contact', $this->payload())
            ->assertOk();

        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/store/contact')
            ->assertOk()
            ->assertJsonPath('data.support_phone', '+201111111111')
            ->assertJsonPath('data.support_whatsapp', '+201222222222')
            ->assertJsonPath('data.address', 'شارع التحرير')
            ->assertJsonMissing(['address_ar' => 'شارع التحرير'])
            ->assertJsonMissing(['address_en' => 'Tahrir Street']);

        $this->withHeader('Accept-Language', 'en-US')->getJson('/api/v1/store/contact')
            ->assertOk()->assertJsonPath('data.address', 'Tahrir Street');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'support_phone' => '+201111111111',
            'support_whatsapp' => '+201222222222',
            'support_email' => 'support@shiny-style.example',
            'address_ar' => 'شارع التحرير',
            'address_en' => 'Tahrir Street',
            'google_maps_url' => 'https://maps.google.com/?q=Shiny+Style',
        ], $overrides);
    }
}
