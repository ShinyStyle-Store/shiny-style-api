<?php

namespace Tests\Unit\Services;

use App\Exceptions\PaymobConfigurationException;
use App\Exceptions\PaymobRequestException;
use App\Services\PaymobClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymobClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.paymob.secret_key' => 'test-secret',
            'services.paymob.api_base_url' => 'https://paymob.test',
            'services.paymob.timeout_seconds' => 30,
            'services.paymob.connect_timeout_seconds' => 10,
        ]);
    }

    public function test_post_json_uses_paymob_base_url_authentication_json_and_accept_headers(): void
    {
        Http::fake([
            'https://paymob.test/*' => Http::response(['ok' => true], 200),
        ]);

        $response = app(PaymobClient::class)->postJson('foundation.test', '/v1/test', ['amount' => 100]);

        $this->assertSame(['ok' => true], $response);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://paymob.test/v1/test'
                && $request->hasHeader('Authorization', 'Token test-secret')
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('Content-Type', 'application/json')
                && $request->data() === ['amount' => 100];
        });
    }

    public function test_missing_secret_key_fails_before_http(): void
    {
        config(['services.paymob.secret_key' => '']);

        try {
            app(PaymobClient::class)->postJson('foundation.test', '/v1/test', []);
            $this->fail('A missing secret key must prevent the request.');
        } catch (PaymobConfigurationException $exception) {
            $this->assertSame('missing_secret_key', $exception->errorCode);
            $this->assertStringNotContainsString('test-secret', $exception->getMessage());
        }
    }

    public function test_invalid_api_url_fails_before_http(): void
    {
        config(['services.paymob.api_base_url' => 'http://paymob.test']);

        $this->expectException(PaymobConfigurationException::class);
        app(PaymobClient::class)->postJson('foundation.test', '/v1/test', []);
    }

    public function test_connection_failure_is_translated_without_provider_details(): void
    {
        Http::fake([
            'https://paymob.test/*' => Http::failedConnection('cURL error 7: private-secret-detail'),
        ]);

        try {
            app(PaymobClient::class)->postJson('foundation.test', '/v1/test', []);
            $this->fail('The connection failure should be translated.');
        } catch (PaymobRequestException $exception) {
            $this->assertSame('provider_connection_error', $exception->errorCode);
            $this->assertStringNotContainsString('private-secret-detail', $exception->getMessage());
        }
    }

    public function test_timeout_is_translated_without_provider_details(): void
    {
        Http::fake([
            'https://paymob.test/*' => Http::failedConnection('cURL error 28: operation timed out with private-secret-detail'),
        ]);

        try {
            app(PaymobClient::class)->postJson('foundation.test', '/v1/test', []);
            $this->fail('The timeout should be translated.');
        } catch (PaymobRequestException $exception) {
            $this->assertSame('provider_timeout', $exception->errorCode);
            $this->assertStringNotContainsString('private-secret-detail', $exception->getMessage());
        }
    }

    #[DataProvider('providerErrorResponses')]
    public function test_provider_http_errors_are_translated_without_raw_bodies(int $status, string $code): void
    {
        Http::fake([
            'https://paymob.test/*' => Http::response(['secret' => 'do-not-expose'], $status),
        ]);

        try {
            app(PaymobClient::class)->postJson('foundation.test', '/v1/test', []);
            $this->fail('The provider error should be translated.');
        } catch (PaymobRequestException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->statusCode);
            $this->assertStringNotContainsString('do-not-expose', $exception->getMessage());
        }
    }

    public static function providerErrorResponses(): array
    {
        return [
            '400' => [400, 'provider_client_error'],
            '401' => [401, 'provider_client_error'],
            '404' => [404, 'provider_client_error'],
            '422' => [422, 'provider_client_error'],
            '500' => [500, 'provider_server_error'],
            '502' => [502, 'provider_server_error'],
            '503' => [503, 'provider_server_error'],
        ];
    }

    public function test_malformed_successful_response_is_rejected(): void
    {
        Http::fake([
            'https://paymob.test/*' => Http::response('not-json', 200),
        ]);

        try {
            app(PaymobClient::class)->postJson('foundation.test', '/v1/test', []);
            $this->fail('A malformed successful response should be rejected.');
        } catch (PaymobRequestException $exception) {
            $this->assertSame('malformed_provider_response', $exception->errorCode);
            $this->assertSame(200, $exception->statusCode);
        }
    }

    public function test_post_requests_are_not_retried_automatically(): void
    {
        Http::fake([
            'https://paymob.test/*' => Http::response(['ok' => true], 200),
        ]);

        app(PaymobClient::class)->postJson('foundation.test', '/v1/test', []);

        Http::assertSentCount(1);
    }
}
