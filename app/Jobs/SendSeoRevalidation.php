<?php

namespace App\Jobs;

use App\Exceptions\SeoRevalidationPermanentException;
use App\Exceptions\SeoRevalidationRetryableException;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SendSeoRevalidation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 7;

    private ?int $retryAfterSeconds = null;

    public function __construct(public readonly string $rawBody) {}

    /** @return list<int> */
    public function backoff(): array
    {
        $delays = [10, 60, 300, 900];
        if ($this->retryAfterSeconds !== null && $this->retryAfterSeconds > 0) {
            $index = min(max($this->attempts() - 1, 0), count($delays) - 1);
            $delays[$index] = max($delays[$index], $this->retryAfterSeconds);
        }

        return $delays;
    }

    public function handle(): void
    {
        if (! config('seo_revalidation.enabled', false)) {
            $this->fail(new SeoRevalidationPermanentException('SEO revalidation is currently disabled.'));

            return;
        }

        $url = config('seo_revalidation.url');
        $secret = config('seo_revalidation.secret');
        if (! is_string($url) || ! str_starts_with($url, 'https://')
            || ! is_string($secret) || strlen($secret) < 32) {
            $this->fail(new SeoRevalidationPermanentException('SEO revalidation URL or secret is not configured securely.'));

            return;
        }

        $timestamp = (string) now('UTC')->timestamp;
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$this->rawBody, $secret);

        try {
            $response = Http::connectTimeout((int) config('seo_revalidation.connect_timeout_seconds', 2))
                ->timeout((int) config('seo_revalidation.timeout_seconds', 5))
                ->withOptions(['allow_redirects' => false])
                ->withHeaders([
                    'X-Shiny-SEO-Timestamp' => $timestamp,
                    'X-Shiny-SEO-Signature' => $signature,
                ])
                ->withBody($this->rawBody, 'application/json; charset=utf-8')
                ->post($url);
        } catch (Throwable $exception) {
            throw new SeoRevalidationRetryableException('The SEO revalidation receiver could not be reached.', previous: $exception);
        }

        if (in_array($response->status(), [408, 425, 429], true) || $response->serverError()) {
            $this->retryAfterSeconds = $this->retryAfterSeconds($response->header('Retry-After'));
            throw new SeoRevalidationRetryableException('The SEO revalidation receiver returned a retryable response.');
        }

        if ($response->status() !== 200) {
            $this->fail(new SeoRevalidationPermanentException('The SEO revalidation receiver rejected the request.'));

            return;
        }

        $event = json_decode($this->rawBody, true, flags: JSON_THROW_ON_ERROR);
        $acknowledgement = $response->json();
        if (! is_array($event)
            || ! is_array($acknowledgement)
            || ($acknowledgement['version'] ?? null) !== 1
            || ($acknowledgement['eventId'] ?? null) !== ($event['eventId'] ?? null)
            || ($acknowledgement['status'] ?? null) !== 'accepted') {
            throw new SeoRevalidationRetryableException('The SEO revalidation receiver returned an invalid acknowledgement.');
        }

        Log::info('SEO revalidation event accepted.', [
            'event_id' => $event['eventId'],
            'entity_type' => $event['entityType'],
            'reason' => $event['reason'],
            'attempt' => $this->attempts(),
            'result' => 'accepted',
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $event = json_decode($this->rawBody, true);
        Log::error('SEO revalidation event exhausted or permanently failed.', [
            'event_id' => is_array($event) ? ($event['eventId'] ?? null) : null,
            'entity_type' => is_array($event) ? ($event['entityType'] ?? null) : null,
            'reason' => is_array($event) ? ($event['reason'] ?? null) : null,
            'attempts' => $this->attempts(),
            'exception' => $exception?->getMessage(),
        ]);
    }

    private function retryAfterSeconds(?string $retryAfter): ?int
    {
        if ($retryAfter === null || trim($retryAfter) === '') {
            return null;
        }

        if (ctype_digit($retryAfter)) {
            return (int) $retryAfter;
        }

        try {
            $seconds = CarbonImmutable::now('UTC')->diffInSeconds(CarbonImmutable::parse($retryAfter)->utc(), false);

            return max(0, (int) $seconds);
        } catch (Throwable) {
            return null;
        }
    }
}
