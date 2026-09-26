<?php

namespace App\Services;

use App\Exceptions\PaymobConfigurationException;
use App\Exceptions\PaymobRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class PaymobClient
{
    public function validateConfiguration(string $path): void
    {
        $this->requiredSecretKey();
        $this->requiredHttpsUrl('api_base_url');
        $this->relativePath($path);
    }

    /**
     * @param array{payment_attempt_public_id?:string, merchant_reference?:string} $diagnosticContext
     * @return array<string, mixed>
     */
    public function postJson(string $operation, string $path, array $payload, array $diagnosticContext = []): array
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

        $this->throwForHttpFailure($response, $operation, $diagnosticContext);

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

    /**
     * @param array{payment_attempt_public_id?:string, merchant_reference?:string} $diagnosticContext
     */
    private function throwForHttpFailure(Response $response, string $operation, array $diagnosticContext): void
    {
        $status = $response->status();

        if ($response->clientError()) {
            $this->logClientError($response, $operation, $diagnosticContext);

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

    /**
     * @param array{payment_attempt_public_id?:string, merchant_reference?:string} $diagnosticContext
     */
    private function logClientError(Response $response, string $operation, array $diagnosticContext): void
    {
        $provider = $response->json();
        $context = [
            'operation' => $operation,
            'provider_status' => $response->status(),
            'payment_attempt_public_id' => $this->safeIdentifier($diagnosticContext['payment_attempt_public_id'] ?? null),
            'merchant_reference' => $this->safeIdentifier($diagnosticContext['merchant_reference'] ?? null),
        ];

        if (is_array($provider)) {
            $detail = null;
            foreach (['detail', 'message', 'error'] as $detailKey) {
                $detail = $this->safeProviderText($provider[$detailKey] ?? null);
                if ($detail !== null) {
                    break;
                }
            }
            if ($detail !== null) {
                $context['provider_detail'] = $detail;
            }

            $validationErrors = $this->validationDiagnostics($provider);
            if ($validationErrors !== []) {
                $context['validation_errors'] = $validationErrors;
            }
        }

        $correlationId = $this->correlationId($response);
        if ($correlationId !== null) {
            $context['provider_correlation_id'] = $correlationId;
        }

        Log::warning('Paymob request rejected by provider.', $context);
    }

    /** @return list<array{field:string,message?:string}> */
    private function validationDiagnostics(array $provider): array
    {
        $diagnostics = [];
        $foundValidationWrapper = false;

        foreach (['errors', 'validation_errors', 'field_errors'] as $key) {
            if (is_array($provider[$key] ?? null)) {
                $foundValidationWrapper = true;
                $this->collectValidationDiagnostics($provider[$key], '', $diagnostics);
            }
        }

        if (! $foundValidationWrapper) {
            foreach ($provider as $field => $error) {
                if (! is_string($field) || ! is_array($error) || $this->isSensitiveResponseKey($field)) {
                    continue;
                }

                $this->collectValidationDiagnostics($error, $field, $diagnostics);
            }
        }

        return array_slice($diagnostics, 0, 20);
    }

    /** @param list<array{field:string,message?:string}> $diagnostics */
    private function collectValidationDiagnostics(mixed $value, string $path, array &$diagnostics, int $depth = 0): void
    {
        if ($depth > 6 || count($diagnostics) >= 20 || ! is_array($value)) {
            return;
        }

        foreach ($value as $field => $error) {
            if (count($diagnostics) >= 20) {
                return;
            }

            $fieldName = is_int($field) ? '' : trim((string) $field);
            $nextPath = $fieldName === ''
                ? $path
                : ($path === '' ? $fieldName : $path.'.'.$fieldName);

            if (is_array($error)) {
                $this->collectValidationDiagnostics($error, $nextPath, $diagnostics, $depth + 1);
                continue;
            }

            if ($nextPath === '' || ! is_string($error)) {
                continue;
            }

            $entry = ['field' => $this->safeFieldName($nextPath)];
            $message = $this->safeProviderText($error);
            if ($message !== null) {
                $entry['message'] = $message;
            }
            $diagnostics[] = $entry;
        }
    }

    private function isSensitiveResponseKey(string $key): bool
    {
        return in_array(strtolower($key), [
            'raw_response',
            'raw_sensitive_body',
            'payload',
            'request',
            'authorization',
            'headers',
            'client_secret',
            'secret_key',
            'public_key',
            'hmac_secret',
        ], true);
    }

    private function safeIdentifier(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return substr(trim($value), 0, 128);
    }

    private function safeFieldName(string $field): string
    {
        return substr(preg_replace('/[^A-Za-z0-9_.\[\]-]/', '', $field) ?: 'unknown', 0, 100);
    }

    private function safeProviderText(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';
        if (str_contains($value, '{') || str_contains($value, '[')) {
            return '[redacted-structured-provider-detail]';
        }
        $value = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[redacted-email]', $value) ?? $value;
        $value = preg_replace('/\b(?:bearer\s+|token\s+|secret\s*[=:]\s*|client[_ -]?secret\s*[=:]\s*)[^\s,;]+/i', '[redacted-secret]', $value) ?? $value;
        $value = preg_replace('/(?<![A-Za-z0-9])\d+(?:[.,]\d+)?(?![A-Za-z0-9])/', '[redacted-number]', $value) ?? $value;
        $sensitiveCheck = str_replace(['[redacted-email]', '[redacted-secret]', '[redacted-number]'], '', $value);
        if (preg_match('/\b(customer|email|phone|address|name|cardholder|pan|cvv|otp)\b/i', $sensitiveCheck) === 1) {
            return '[redacted-sensitive-provider-detail]';
        }

        return substr($value, 0, 300);
    }

    private function correlationId(Response $response): ?string
    {
        foreach (['x-request-id', 'request-id', 'x-correlation-id', 'correlation-id'] as $header) {
            $value = $response->header($header);
            if (is_string($value) && trim($value) !== '') {
                return substr(trim($value), 0, 128);
            }
        }

        return null;
    }
}
