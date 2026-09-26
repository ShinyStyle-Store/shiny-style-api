<?php

namespace App\Services;

use App\Exceptions\PaymobConfigurationException;
use App\Exceptions\PaymobRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class PaymobClient
{
    public function validateConfiguration(string $path): void
    {
        $this->requiredSecretKey();
        $this->requiredHttpsUrl('api_base_url');
        $this->relativePath($path);
    }

    /** @return array<string, mixed> */
    public function postJson(string $operation, string $path, array $payload): array
    {
        $this->validateConfiguration($path);
        $secretKey = $this->requiredSecretKey();
        $baseUrl = $this->requiredHttpsUrl('api_base_url');
        $path = $this->relativePath($path);

        try {
            $response = Http::baseUrl($baseUrl)
                ->withHeaders([
                    'Authorization' => 'Token '.$secretKey,
                ])
                ->asJson()
                ->acceptJson()
                ->connectTimeout((int) config('services.paymob.connect_timeout_seconds', 10))
                ->timeout((int) config('services.paymob.timeout_seconds', 30))
                ->post($path, $payload);
        } catch (ConnectionException $exception) {
            $timedOut = str_contains(strtolower($exception->getMessage()), 'timed out');

            throw new PaymobRequestException(
                $timedOut ? 'provider_timeout' : 'provider_connection_error',
                $timedOut
                    ? "Paymob {$operation} timed out."
                    : "Paymob {$operation} could not connect.",
                $operation,
                previous: $exception,
            );
        }

        $this->throwForHttpFailure($response, $operation);

        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new PaymobRequestException(
                'malformed_provider_response',
                "Paymob {$operation} returned an invalid response.",
                $operation,
                $response->status(),
            );
        }

        return $decoded;
    }

    private function requiredSecretKey(): string
    {
        $secretKey = config('services.paymob.secret_key');

        if (! is_string($secretKey) || trim($secretKey) === '') {
            throw new PaymobConfigurationException(
                'missing_secret_key',
                'Paymob secret key is not configured.',
            );
        }

        return trim($secretKey);
    }

    private function requiredHttpsUrl(string $key): string
    {
        $url = config("services.paymob.{$key}");
        $parts = is_string($url) ? parse_url(trim($url)) : false;

        if (! is_string($url)
            || trim($url) === ''
            || $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            throw new PaymobConfigurationException(
                "invalid_{$key}",
                "Paymob {$key} is invalid.",
            );
        }

        return rtrim(trim($url), '/');
    }

    private function relativePath(string $path): string
    {
        $path = trim($path);

        if ($path === '' || ! str_starts_with($path, '/') || parse_url($path, PHP_URL_SCHEME) !== null) {
            throw new PaymobConfigurationException(
                'invalid_endpoint_path',
                'The Paymob endpoint path is invalid.',
            );
        }

        return $path;
    }

    private function throwForHttpFailure(Response $response, string $operation): void
    {
        $status = $response->status();

        if ($response->clientError()) {
            throw new PaymobRequestException(
                'provider_client_error',
                "Paymob {$operation} was rejected by the provider.",
                $operation,
                $status,
            );
        }

        if ($response->serverError()) {
            throw new PaymobRequestException(
                'provider_server_error',
                "Paymob {$operation} failed at the provider.",
                $operation,
                $status,
            );
        }

        if (! $response->successful()) {
            throw new PaymobRequestException(
                'unexpected_provider_response',
                "Paymob {$operation} returned an unexpected response.",
                $operation,
                $status,
            );
        }
    }
}
