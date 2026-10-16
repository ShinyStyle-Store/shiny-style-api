# Laravel SEO Revalidation

Laravel queues a signed invalidation event after the database commit. The Next.js receiver must be deployed and tested before enabling dispatch. The webhook requests a fresh read; it never sends product prices, inventory quantities, or replacement content.

## Runtime configuration

Set these values in the Laravel runtime secret/configuration store:

| Variable | Required value |
|---|---|
| `SEO_REVALIDATION_ENABLED` | Keep `false` until both applications and the worker are ready. |
| `SEO_REVALIDATION_SECRET` | Same high entropy secret text on both servers, at least 32 bytes. |
| `SEO_REVALIDATION_URL` | Full HTTPS URL for `POST /api/internal/seo/revalidate` on the Next server. |
| `SEO_REVALIDATION_QUEUE_CONNECTION` | Persistent asynchronous driver: `database`, `redis`, `sqs`, or `beanstalkd`. Defaults to `QUEUE_CONNECTION`. `sync`, `null`, and non-persistent drivers are rejected. |
| `SEO_REVALIDATION_QUEUE` | Queue name; defaults to `seo-revalidation`. |
| `SEO_REVALIDATION_CONNECT_TIMEOUT_SECONDS` | Connect timeout; defaults to 2. |
| `SEO_REVALIDATION_TIMEOUT_SECONDS` | Total HTTP timeout; defaults to 5. |

The repository's queue default is `database`; its jobs and failed-jobs tables already exist in the queue migration. Configure a supervised worker for the selected connection and queue, for example `php artisan queue:work database --queue=seo-revalidation --tries=5 --timeout=10`. The job timeout is 7 seconds and the database queue `retry_after` is 90 seconds. Keep the worker running as a managed process and monitor `failed_jobs` and Laravel error logs. A failed event can be replayed with Laravel's existing `queue:retry` command; its event body and ID remain unchanged while the request timestamp and HMAC are regenerated.

The existing scheduler runs `seo:revalidation-time-boundaries` once per minute with overlap protection and single-server coordination. The host must invoke `php artisan schedule:run` every minute, and its shared cache store must support atomic locks. This repository configures the database cache store by default. A durable checkpoint migration records the start of boundary tracking; do not advance it manually. When the integration is disabled, the checkpoint does not advance, so scheduled changes during the disabled period are still inspected after activation.

## Event production

Products use `catalog`, `sitemap`, and `metaFeed`; slug and visibility transitions also invalidate `redirects`. Categories use `catalog` and `sitemap`. Content pages use `content` and `sitemap`. Store/SEO settings use all six scopes conservatively. Manual redirects use `redirects` and `sitemap`. Paths are locale-prefixed public paths from the current canonical mapping; a slug change sends both old and new paths. Shared changes include both `ar` and `en`; localized SEO changes include only the changed locale.

The scheduler checks visible, published products' publication timestamps and enabled offers attached to currently visible products. It scans the complete interval after the saved checkpoint, including downtime, and sends one broad `time_boundary` invalidation at the latest crossed timestamp. It enqueues before advancing the checkpoint; a queue failure leaves the interval available for retry. Repeated boundary notifications are safe invalidations. The checkpoint timestamp is operational state and is not content `lastmod`.

Queue enqueue failures after a successful business commit are logged and reported without changing the API result. This implementation does not provide an outbox or exactly-once delivery; a process failure between commit and queue insertion remains a recovery gap covered by the documented periodic cache TTL and operational monitoring.

## Contract and verification boundary

The Laravel job signs the exact UTF-8 JSON body with `HMAC-SHA256(timestamp + "." + body)`, sends the two `X-Shiny-SEO-*` headers, does not follow redirects, uses the contract's five attempts with 10/60/300/900 second backoffs, and retries network errors, 408, 425, 429, 5xx, and invalid acknowledgements. Other non-2xx responses and missing/invalid runtime credentials are terminal failed jobs. A successful response must acknowledge version 1, the same event ID, and `accepted` status.

No real storefront requests are made by tests. Before activation, configure and verify the matching Next receiver URL and secret, deploy the checkpoint migration, start the persistent queue worker, register the every-minute scheduler, confirm clock synchronization, and test delivery between the two deployed environments. Keep the feature flag false until these checks pass.
