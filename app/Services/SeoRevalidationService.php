<?php

namespace App\Services;

use App\Jobs\SendSeoRevalidation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class SeoRevalidationService
{
    public function __construct(private readonly SeoRevalidationPayloadFactory $payloads) {}

    /** @param list<string> $locales @param list<string> $scopes @param list<string> $additionalPaths */
    public function afterCommitForEntity(
        Model $entity,
        string $reason,
        array $locales,
        array $scopes,
        array $additionalPaths = [],
    ): void {
        if (! config('seo_revalidation.enabled', false)) {
            return;
        }

        try {
            $payload = $this->payloads->forEntity($entity, $reason, $locales, $scopes, $additionalPaths);
            $this->afterCommit($payload);
        } catch (Throwable $exception) {
            $this->logPayloadFailure($entity::class, $reason, $exception);
        }
    }

    /** @param list<string> $locales @param list<string> $scopes @param list<string> $paths */
    public function afterCommitForTarget(
        string $entityType,
        string $entityId,
        string $reason,
        array $locales,
        array $scopes,
        array $paths = [],
        ?CarbonImmutable $occurredAt = null,
    ): void {
        if (! config('seo_revalidation.enabled', false)) {
            return;
        }

        try {
            $this->afterCommit($this->payloads->make(
                $entityType,
                $entityId,
                $reason,
                $locales,
                $scopes,
                $paths,
                $occurredAt,
            ));
        } catch (Throwable $exception) {
            $this->logPayloadFailure($entityType, $reason, $exception);
        }
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(array $payload): bool
    {
        $connectionName = (string) config('seo_revalidation.queue_connection');
        $driver = config('queue.connections.'.$connectionName.'.driver');
        if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            Log::critical('SEO revalidation requires a persistent asynchronous queue connection.', [
                'event_id' => $payload['eventId'],
                'queue_connection' => $connectionName,
            ]);

            return false;
        }

        try {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            Queue::connection($connectionName)->push(
                new SendSeoRevalidation($body),
                '',
                (string) config('seo_revalidation.queue', 'seo-revalidation'),
            );

            return true;
        } catch (Throwable $exception) {
            Log::error('Unable to enqueue SEO revalidation event.', [
                'event_id' => $payload['eventId'],
                'entity_type' => $payload['entityType'],
                'reason' => $payload['reason'],
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /** @param array<string, mixed> $payload */
    private function afterCommit(array $payload): void
    {
        DB::afterCommit(function () use ($payload): void {
            $this->enqueue($payload);
        });
    }

    private function logPayloadFailure(string $entityType, string $reason, Throwable $exception): void
    {
        Log::error('Unable to prepare SEO revalidation event after a public data change.', [
            'entity_type' => $entityType,
            'reason' => $reason,
            'exception' => $exception::class,
        ]);
    }
}
