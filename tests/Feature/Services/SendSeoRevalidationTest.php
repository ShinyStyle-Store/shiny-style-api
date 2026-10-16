<?php

namespace Tests\Feature\Services;

use App\Exceptions\SeoRevalidationRetryableException;
use App\Jobs\SendSeoRevalidation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendSeoRevalidationTest extends TestCase
{
    private const RAW_BODY = '{"version":1,"eventId":"d7430a27-d765-4a88-bb82-9216bd789501","occurredAt":"2026-10-15T18:15:00Z","entityType":"product","entityId":"123","reason":"slug_changed","locales":["ar","en"],"scopes":["catalog","sitemap","redirects","metaFeed"],"paths":["/ar/products/old-blanket","/ar/products/winter-blanket","/en/products/old-blanket","/en/products/winter-blanket"]}';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'seo_revalidation.enabled' => true,
            'seo_revalidation.url' => 'https://next.example/api/internal/seo/revalidate',
            'seo_revalidation.secret' => 'shiny-style-seo-v1-test-secret-only',
            'seo_revalidation.connect_timeout_seconds' => 2,
            'seo_revalidation.timeout_seconds' => 5,
        ]);
        Http::preventStrayRequests();
    }

    public function test_job_signs_the_exact_contract_body_and_accepts_matching_acknowledgement(): void
    {
        $eventId = 'd7430a27-d765-4a88-bb82-9216bd789501';
        $this->travelTo(CarbonImmutable::createFromTimestampUTC(1792088100));
        Http::fake([
            'next.example/api/internal/seo/revalidate' => Http::response([
                'version' => 1,
                'eventId' => $eventId,
                'status' => 'accepted',
            ], 200),
        ]);

        (new SendSeoRevalidation(self::RAW_BODY))->handle();

        Http::assertSent(function (Request $request) use ($eventId): bool {
            return $request->method() === 'POST'
                && $request->body() === self::RAW_BODY
                && $request->header('X-Shiny-SEO-Timestamp') === ['1792088100']
                && $request->header('X-Shiny-SEO-Signature') === [
                    'sha256=bda302e0f6137cd02080b0e28e1ba4f7f5ff55eb1b3e8512d1236ea8371c1964',
                ]
                && $request->data()['eventId'] === $eventId;
        });
    }

    public function test_retry_keeps_body_and_event_id_but_signs_with_a_new_timestamp(): void
    {
        $eventId = 'd7430a27-d765-4a88-bb82-9216bd789501';
        $job = new SendSeoRevalidation(self::RAW_BODY);
        Http::fake([
            'next.example/api/internal/seo/revalidate' => Http::sequence()
                ->push(['error' => 'temporary'], 503)
                ->push(['version' => 1, 'eventId' => $eventId, 'status' => 'accepted'], 200),
        ]);

        try {
            $job->handle();
            $this->fail('The temporary receiver failure should be retried.');
        } catch (SeoRevalidationRetryableException) {
        }

        $this->travel(1)->seconds();
        $job->handle();
        $requests = Http::recorded()->map(fn (array $recorded): Request => $recorded[0])->values();

        $this->assertCount(2, $requests);
        $this->assertSame(self::RAW_BODY, $requests[0]->body());
        $this->assertSame($requests[0]->body(), $requests[1]->body());
        $this->assertNotSame(
            $requests[0]->header('X-Shiny-SEO-Signature'),
            $requests[1]->header('X-Shiny-SEO-Signature'),
        );
        $this->assertSame('d7430a27-d765-4a88-bb82-9216bd789501', $requests[1]->data()['eventId']);
    }

    public function test_utf8_body_is_sent_byte_for_byte_and_signed_as_utf8(): void
    {
        $body = '{"version":1,"eventId":"88b02ba4-a0f7-41ef-8c9a-407d79bbef01","occurredAt":"2026-10-15T18:15:00Z","entityType":"product","entityId":"منتج-123","reason":"updated","locales":["ar"],"scopes":["catalog"],"paths":[]}';
        $this->travelTo(CarbonImmutable::createFromTimestampUTC(1792088100));
        Http::fake([
            'next.example/api/internal/seo/revalidate' => Http::response([
                'version' => 1,
                'eventId' => '88b02ba4-a0f7-41ef-8c9a-407d79bbef01',
                'status' => 'accepted',
            ], 200),
        ]);

        (new SendSeoRevalidation($body))->handle();

        $expected = 'sha256='.hash_hmac('sha256', '1792088100.'.$body, 'shiny-style-seo-v1-test-secret-only');
        Http::assertSent(fn (Request $request): bool =>
            $request->body() === $body
            && $request->header('X-Shiny-SEO-Signature') === [$expected]);
    }

    public function test_five_attempt_retry_policy_uses_the_contract_delays(): void
    {
        $job = new SendSeoRevalidation(self::RAW_BODY);

        $this->assertSame(5, $job->tries);
        $this->assertSame([10, 60, 300, 900], $job->backoff());
    }

    public function test_rate_limit_retry_after_is_respected_without_retrying_inside_the_request(): void
    {
        $job = new SendSeoRevalidation(self::RAW_BODY);
        Http::fake([
            'next.example/api/internal/seo/revalidate' => Http::response(
                ['error' => 'rate limited'],
                429,
                ['Retry-After' => '120'],
            ),
        ]);

        try {
            $job->handle();
            $this->fail('A rate limit response should be released to the queue.');
        } catch (SeoRevalidationRetryableException) {
        }

        $this->assertSame([120, 60, 300, 900], $job->backoff());
        Http::assertSentCount(1);
    }
}
