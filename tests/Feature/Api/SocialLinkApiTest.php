<?php

namespace Tests\Feature\Api;

use App\Models\AdminMembership;
use App\Models\SocialLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialLinkApiTest extends TestCase
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

    public function test_both_lists_are_empty_before_any_links_are_created(): void
    {
        $this->getJson('/api/v1/store/social-links')
            ->assertOk()->assertJson(['data' => []]);
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/store/social-links')
            ->assertOk()->assertJson(['data' => []]);
    }

    public function test_admin_routes_require_admin_authorization(): void
    {
        $this->getJson('/api/v1/admin/store/social-links')->assertUnauthorized();
        $this->postJson('/api/v1/admin/store/social-links', $this->payload())->assertUnauthorized();

        $customer = User::factory()->create();
        $token = $customer->createToken('customer', ['customer-access'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/admin/store/social-links')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/admin/store/social-links', $this->payload())->assertForbidden();
    }

    public function test_admin_can_create_update_and_delete_a_social_link(): void
    {
        $created = $this->withToken($this->adminToken)
            ->postJson('/api/v1/admin/store/social-links', $this->payload([
                'platformCode' => 'facebook',
                'url' => 'https://www.facebook.com/shiny-style',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.platformCode', 'facebook')
            ->assertJsonPath('data.isEnabled', true);
        $id = $created->json('data.id');

        $this->withToken($this->adminToken)
            ->patchJson('/api/v1/admin/store/social-links/'.$id, [
                'isEnabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.isEnabled', false);

        $this->withToken($this->adminToken)
            ->deleteJson('/api/v1/admin/store/social-links/'.$id)
            ->assertNoContent();
        $this->assertDatabaseMissing('social_links', ['id' => $id]);
    }

    public function test_platform_registry_accepts_supported_hosts_and_rejects_unknown_platforms(): void
    {
        $valid = [
            ['platformCode' => 'facebook', 'url' => 'https://facebook.com/shiny-style'],
            ['platformCode' => 'instagram', 'url' => 'https://www.instagram.com/shiny-style'],
            ['platformCode' => 'tiktok', 'url' => 'https://www.tiktok.com/@shiny-style'],
            ['platformCode' => 'whatsapp', 'url' => 'https://wa.me/201111111111'],
        ];

        foreach ($valid as $payload) {
            $this->withToken($this->adminToken)
                ->postJson('/api/v1/admin/store/social-links', $this->payload($payload))
                ->assertCreated();
        }

        $this->withToken($this->adminToken)
            ->postJson('/api/v1/admin/store/social-links', $this->payload([
                'platformCode' => 'youtube',
                'url' => 'https://youtube.com/shiny-style',
                'platform_code' => 'legacy-field',
            ]))
            ->assertUnprocessable()->assertJsonValidationErrors(['platformCode', 'platform_code']);
    }

    public function test_duplicate_platforms_return_a_validation_error(): void
    {
        $this->withToken($this->adminToken)
            ->postJson('/api/v1/admin/store/social-links', $this->payload())
            ->assertCreated();

        $this->withToken($this->adminToken)
            ->postJson('/api/v1/admin/store/social-links', $this->payload([
                'url' => 'https://www.facebook.com/another-page',
            ]))
            ->assertUnprocessable()->assertJsonValidationErrors('platformCode');
    }

    public function test_facebook_page_and_group_can_coexist_but_each_code_is_unique(): void
    {
        $this->withToken($this->adminToken)
            ->postJson('/api/v1/admin/store/social-links', $this->payload([
                'platformCode' => 'facebook',
                'url' => 'https://www.facebook.com/shiny-style',
            ]))->assertCreated();

        $this->withToken($this->adminToken)
            ->postJson('/api/v1/admin/store/social-links', $this->payload([
                'platformCode' => 'facebook_group',
                'url' => 'https://www.facebook.com/groups/shiny-style',
            ]))->assertCreated();

        $this->assertDatabaseCount('social_links', 2);

        foreach ([
            ['platformCode' => 'facebook', 'url' => 'https://facebook.com/another-page'],
            ['platformCode' => 'facebook_group', 'url' => 'https://facebook.com/groups/another-group'],
        ] as $duplicate) {
            $this->withToken($this->adminToken)
                ->postJson('/api/v1/admin/store/social-links', $this->payload($duplicate))
                ->assertUnprocessable()->assertJsonValidationErrors('platformCode');
        }
    }

    public function test_invalid_and_lookalike_urls_are_rejected(): void
    {
        foreach ([
            'http://facebook.com/shiny-style',
            'https://example.com/shiny-style',
            'https://facebook.com.evil.example/shiny-style',
            'https://user:password@facebook.com/shiny-style',
            'https://facebook.com:8443/shiny-style',
            'https://wa.me.evil.example/201111111111',
        ] as $url) {
            $this->withToken($this->adminToken)
                ->postJson('/api/v1/admin/store/social-links', $this->payload(['url' => $url]))
                ->assertUnprocessable()->assertJsonValidationErrors('url');
        }
    }

    public function test_facebook_page_and_group_urls_cannot_be_assigned_to_the_wrong_platform(): void
    {
        $this->withToken($this->adminToken)
            ->postJson('/api/v1/admin/store/social-links', $this->payload([
                'platformCode' => 'facebook_group',
                'url' => 'https://www.facebook.com/shiny-style',
            ]))
            ->assertUnprocessable()->assertJsonValidationErrors('url');

        $this->withToken($this->adminToken)
            ->postJson('/api/v1/admin/store/social-links', $this->payload([
                'platformCode' => 'facebook',
                'url' => 'https://www.facebook.com/groups/shiny-style',
            ]))
            ->assertUnprocessable()->assertJsonValidationErrors('url');
    }

    public function test_disabled_links_are_hidden_publicly_and_ordering_is_sort_order_then_id(): void
    {
        $first = SocialLink::query()->create([
            'platform_code' => 'facebook', 'url' => 'https://facebook.com/shiny-style', 'is_enabled' => true, 'sort_order' => 2,
        ]);
        $second = SocialLink::query()->create([
            'platform_code' => 'instagram', 'url' => 'https://instagram.com/shiny-style', 'is_enabled' => true, 'sort_order' => 1,
        ]);
        SocialLink::query()->create([
            'platform_code' => 'tiktok', 'url' => 'https://tiktok.com/@shiny-style', 'is_enabled' => false, 'sort_order' => 3,
        ]);

        $this->getJson('/api/v1/store/social-links')
            ->assertOk()
            ->assertJsonPath('data.0.platformCode', 'instagram')
            ->assertJsonPath('data.1.platformCode', 'facebook')
            ->assertJsonMissing(['platformCode' => 'tiktok'])
            ->assertJsonStructure(['data' => [['platformCode', 'url']]]);

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/store/social-links')
            ->assertOk()
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.2.isEnabled', false);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'platformCode' => 'facebook',
            'url' => 'https://facebook.com/shiny-style',
            'isEnabled' => true,
            'sortOrder' => 1,
        ], $overrides);
    }
}
