<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Product;
use App\Models\SeoRedirect;
use App\Models\User;
use App\Support\SeoCanonicalOrigin;
use App\Support\SeoRedirectPath;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SeoRedirectService
{
    private const CACHE_SECONDS = 600;

    private bool $domainScopeResolved = false;

    private ?string $resolvedDomainScope = null;

    public function __construct(
        private readonly SeoSettingsService $settings,
        private readonly SeoAuditService $audits,
        private readonly CategoryHierarchyService $categories,
    ) {}

    public function withMutationLock(Closure $callback): mixed
    {
        return Cache::lock('seo-redirect-mutations', 30)->block(5, $callback);
    }

    public function isProductPubliclyVisible(int $productId): bool
    {
        $visibleCategoryIds = $this->categories->visibilitySnapshot()->visibleIds();

        return Product::query()->visible($visibleCategoryIds)->whereKey($productId)->exists();
    }

    /**
     * @param list<string> $locales
     */
    public function recordProductSlugChange(
        Product $product,
        string $oldSlug,
        array $locales,
        bool $wasPublic,
        ?User $actor,
    ): void {
        if ($oldSlug === (string) $product->slug) {
            return;
        }

        $scope = $this->currentDomainScope();
        foreach ($locales as $locale) {
            $newPath = SeoRedirectPath::productPath($locale, (string) $product->slug);
            if ($this->sourcePathExists($newPath)) {
                throw ValidationException::withMessages([
                    'slug' => 'This slug is reserved by SEO redirect history.',
                ]);
            }
        }

        if (! $wasPublic) {
            return;
        }

        if ($scope === null) {
            throw ValidationException::withMessages([
                'slug' => 'Configure the canonical store domain before changing the slug of a public product.',
            ]);
        }

        $isPublicAfterUpdate = $this->isProductPubliclyVisible((int) $product->getKey());
        $invalidated = [];
        $previousLocalizedPaths = array_map(
            static fn (string $locale): string => SeoRedirectPath::productPath($locale, $oldSlug),
            $locales,
        );
        $historicalRedirects = SeoRedirect::query()
            ->where(function (Builder $query) use ($product, $previousLocalizedPaths): void {
                $query->where(function (Builder $entityQuery) use ($product): void {
                    $entityQuery->where('entity_type', 'product')
                        ->where('entity_id', $product->getKey());
                })->orWhereIn('destination_path', $previousLocalizedPaths);
            })
            ->lockForUpdate()
            ->get();

        foreach ($historicalRedirects as $redirect) {
            $destination = SeoRedirectPath::parse($redirect->destination_path);
            if (($destination['entityType'] ?? null) !== 'product'
                || ($destination['locale'] ?? null) === null
                || (! in_array($redirect->destination_path, $previousLocalizedPaths, true)
                    && ($redirect->entity_type !== 'product' || (int) $redirect->entity_id !== (int) $product->getKey()))) {
                continue;
            }

            $newDestination = SeoRedirectPath::productPath($destination['locale'], (string) $product->slug);
            if ($redirect->destination_path === $newDestination) {
                continue;
            }

            $before = $this->snapshot($redirect);
            $redirect->destination_path = $newDestination;
            $redirect->save();
            $this->audits->record(
                $actor === null ? null : (int) $actor->getKey(),
                'seo_redirect',
                (int) $redirect->getKey(),
                'update',
                $before,
                $this->snapshot($redirect),
            );
            $invalidated[] = [$redirect->domain_scope, $redirect->source_path];
        }

        foreach ($locales as $locale) {
            $sourcePath = SeoRedirectPath::productPath($locale, $oldSlug);
            $destinationPath = SeoRedirectPath::productPath($locale, (string) $product->slug);
            $redirect = SeoRedirect::query()
                ->forSource($scope, $sourcePath)
                ->lockForUpdate()
                ->first();

            if ($redirect !== null
                && ($redirect->entity_type !== 'product' || (int) $redirect->entity_id !== (int) $product->getKey())) {
                throw ValidationException::withMessages([
                    'slug' => 'The old product path conflicts with an existing SEO redirect.',
                ]);
            }

            $creating = $redirect === null;
            $before = $creating ? null : $this->snapshot($redirect);
            $redirect ??= new SeoRedirect;
            $redirect->fill([
                'domain_scope' => $scope,
                'source_path' => $sourcePath,
                'source_hash' => hash('sha256', $sourcePath),
                'destination_path' => $destinationPath,
                'status_code' => 301,
                'is_active' => $isPublicAfterUpdate,
                'reason' => 'slug_change',
                'entity_type' => 'product',
                'entity_id' => (int) $product->getKey(),
                ...($creating ? ['created_by' => $actor?->getKey()] : []),
            ]);
            $redirect->save();
            $this->audits->record(
                $actor === null ? null : (int) $actor->getKey(),
                'seo_redirect',
                (int) $redirect->getKey(),
                $creating ? 'create' : 'update',
                $before,
                $this->snapshot($redirect),
            );
            $invalidated[] = [$scope, $sourcePath];
        }

        $this->forgetAfterCommit($invalidated);
    }

    /** @return array{destinationPath: string, statusCode: int}|null */
    public function resolve(string $sourcePath): ?array
    {
        $sourcePath = SeoRedirectPath::normalize($sourcePath);
        $scope = $this->currentDomainScope();
        if ($sourcePath === null || $scope === null) {
            return null;
        }

        $redirectData = Cache::remember(
            $this->cacheKey($scope, $sourcePath),
            self::CACHE_SECONDS,
            function () use ($scope, $sourcePath): array {
                $redirect = SeoRedirect::query()
                    ->forSource($scope, $sourcePath)
                    ->where('is_active', true)
                    ->first();

                if ($redirect === null) {
                    return ['found' => false];
                }

                return [
                    'found' => true,
                    'destinationPath' => $redirect->destination_path,
                    'statusCode' => (int) $redirect->status_code,
                ];
            },
        );

        if (($redirectData['found'] ?? false) !== true
            || ! in_array($redirectData['statusCode'] ?? null, [301, 308], true)
            || $redirectData['destinationPath'] === $sourcePath
            || $this->isPublicPath($sourcePath)
            || ! $this->isPublicPath($redirectData['destinationPath'])) {
            return null;
        }

        $destinationHash = hash('sha256', $redirectData['destinationPath']);
        if (SeoRedirect::query()->where('domain_scope', $scope)
            ->where('source_hash', $destinationHash)
            ->where('source_path', $redirectData['destinationPath'])
            ->where('is_active', true)
            ->exists()) {
            return null;
        }

        return [
            'destinationPath' => $redirectData['destinationPath'],
            'statusCode' => $redirectData['statusCode'],
        ];
    }

    public function createManual(array $data, User $actor): SeoRedirect
    {
        return $this->withMutationLock(fn (): SeoRedirect => DB::transaction(function () use ($data, $actor): SeoRedirect {
            $scope = $this->currentDomainScopeOrFail();
            $sourcePath = $this->validatedPath($data['source_path'] ?? null, 'sourcePath');
            $destinationPath = $this->validatedPath($data['destination_path'] ?? null, 'destinationPath');
            $destination = $this->publicPathEntity($destinationPath);

            if ($sourcePath === $destinationPath || $this->publicPathEntity($sourcePath) !== null) {
                throw ValidationException::withMessages([
                    'sourcePath' => 'The source path is live or would create a redirect loop.',
                ]);
            }
            if ($destination === null) {
                throw ValidationException::withMessages([
                    'destinationPath' => 'The destination must be a currently public internal page.',
                ]);
            }
            if ($this->sourcePathExists($sourcePath)) {
                throw ValidationException::withMessages([
                    'sourcePath' => 'This path is already reserved by redirect history.',
                ]);
            }
            $this->assertNoRedirectChain($scope, $sourcePath, $destinationPath);

            $redirect = SeoRedirect::query()->create([
                'domain_scope' => $scope,
                'source_path' => $sourcePath,
                'source_hash' => hash('sha256', $sourcePath),
                'destination_path' => $destinationPath,
                'status_code' => (int) $data['status_code'],
                'is_active' => true,
                'reason' => 'manual',
                'entity_type' => $destination->getMorphClass(),
                'entity_id' => (int) $destination->getKey(),
                'created_by' => (int) $actor->getKey(),
            ]);
            $this->audits->record((int) $actor->getKey(), 'seo_redirect', (int) $redirect->getKey(), 'create', null, $this->snapshot($redirect));
            $this->forgetAfterCommit([[$scope, $sourcePath]]);

            return $redirect;
        }));
    }

    public function updateManual(SeoRedirect $redirect, array $data, User $actor): SeoRedirect
    {
        return $this->withMutationLock(fn (): SeoRedirect => DB::transaction(function () use ($redirect, $data, $actor): SeoRedirect {
            $locked = SeoRedirect::query()->whereKey($redirect->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);
            $destinationPath = array_key_exists('destination_path', $data)
                ? $this->validatedPath($data['destination_path'], 'destinationPath')
                : $locked->destination_path;
            $statusCode = (int) ($data['status_code'] ?? $locked->status_code);
            $isActive = (bool) ($data['is_active'] ?? $locked->is_active);

            if ($isActive) {
                $destination = $this->publicPathEntity($destinationPath);
                if ($destination === null) {
                    throw ValidationException::withMessages([
                        'destinationPath' => 'The destination must be a currently public internal page.',
                    ]);
                }
                if ($locked->source_path === $destinationPath
                    || $this->publicPathEntity($locked->source_path) !== null) {
                    throw ValidationException::withMessages([
                        'isActive' => 'An active redirect cannot shadow a live page or create a loop.',
                    ]);
                }
                $this->assertNoRedirectChain($locked->domain_scope, $locked->source_path, $destinationPath, (int) $locked->getKey());
                $locked->entity_type = $destination->getMorphClass();
                $locked->entity_id = (int) $destination->getKey();
            }

            $locked->destination_path = $destinationPath;
            $locked->status_code = $statusCode;
            $locked->is_active = $isActive;
            $locked->save();
            if ($before !== $this->snapshot($locked)) {
                $this->audits->record((int) $actor->getKey(), 'seo_redirect', (int) $locked->getKey(), 'update', $before, $this->snapshot($locked));
                $this->forgetAfterCommit([[$locked->domain_scope, $locked->source_path]]);
            }

            return $locked;
        }));
    }

    public function deactivate(SeoRedirect $redirect, User $actor): SeoRedirect
    {
        return $this->withMutationLock(fn (): SeoRedirect => DB::transaction(function () use ($redirect, $actor): SeoRedirect {
            $locked = SeoRedirect::query()->whereKey($redirect->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->is_active) {
                return $locked;
            }

            $before = $this->snapshot($locked);
            $locked->is_active = false;
            $locked->save();
            $this->audits->record((int) $actor->getKey(), 'seo_redirect', (int) $locked->getKey(), 'delete', $before, $this->snapshot($locked));
            $this->forgetAfterCommit([[$locked->domain_scope, $locked->source_path]]);

            return $locked;
        }));
    }

    public function sourcePathExists(string $path): bool
    {
        $query = SeoRedirect::query()->where('source_path', $path);
        $scope = $this->currentDomainScope();
        if ($scope !== null) {
            $query->where('domain_scope', $scope);
        }

        return $query->exists();
    }

    public function slugPathIsReserved(string $entityType, string $slug): bool
    {
        $segment = match ($entityType) {
            'product' => 'products',
            'category' => 'category',
            default => null,
        };
        if ($segment === null) {
            return false;
        }

        foreach (['ar', 'en'] as $locale) {
            if ($this->sourcePathExists('/'.$locale.'/'.$segment.'/'.$slug)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function snapshot(SeoRedirect $redirect): array
    {
        return [
            'domainScope' => $redirect->domain_scope,
            'sourcePath' => $redirect->source_path,
            'sourceHash' => $redirect->source_hash,
            'destinationPath' => $redirect->destination_path,
            'statusCode' => (int) $redirect->status_code,
            'isActive' => (bool) $redirect->is_active,
            'reason' => $redirect->reason,
            'entityType' => $redirect->entity_type,
            'entityId' => $redirect->entity_id === null ? null : (int) $redirect->entity_id,
            'createdBy' => $redirect->created_by === null ? null : (int) $redirect->created_by,
        ];
    }

    private function currentDomainScope(): ?string
    {
        if ($this->domainScopeResolved) {
            return $this->resolvedDomainScope;
        }

        $setting = $this->settings->find('canonicalBaseUrl');

        $this->resolvedDomainScope = SeoCanonicalOrigin::domainScope($setting?->value_json);
        $this->domainScopeResolved = true;

        return $this->resolvedDomainScope;
    }

    private function currentDomainScopeOrFail(): string
    {
        return $this->currentDomainScope() ?? throw ValidationException::withMessages([
            'canonicalBaseUrl' => 'Configure a valid canonical store domain before managing redirects.',
        ]);
    }

    private function validatedPath(mixed $path, string $field): string
    {
        $normalized = SeoRedirectPath::normalize($path);
        if ($normalized === null) {
            throw ValidationException::withMessages([
                $field => 'Use a normalized, locale-prefixed internal product, category, or mapped content path.',
            ]);
        }

        return $normalized;
    }

    private function isPublicPath(string $path): bool
    {
        return $this->publicPathEntity($path) !== null;
    }

    private function publicPathEntity(string $path): ?Model
    {
        $parts = SeoRedirectPath::parse($path);
        if ($parts === null) {
            return null;
        }

        return match ($parts['entityType']) {
            'product' => $this->publicProduct($parts['slug']),
            'category' => $this->categories->visibilitySnapshot()->visibleCategoryBySlug($parts['slug']),
            'content_page' => ContentPage::query()->where('slug', $parts['slug'])->first(),
            default => null,
        };
    }

    private function publicProduct(string $slug): ?Product
    {
        $visibleCategoryIds = $this->categories->visibilitySnapshot()->visibleIds();

        return Product::query()->visible($visibleCategoryIds)->where('slug', $slug)->first();
    }

    private function assertNoRedirectChain(string $scope, string $source, string $destination, ?int $exceptId = null): void
    {
        $visited = [$source => true];
        $current = $destination;
        for ($hop = 0; $hop < 10; $hop++) {
            if (isset($visited[$current])) {
                throw ValidationException::withMessages([
                    'destinationPath' => 'The redirect would create a loop.',
                ]);
            }
            $visited[$current] = true;
            $hash = hash('sha256', $current);
            $query = SeoRedirect::query()->where('domain_scope', $scope)
                ->where('source_hash', $hash)
                ->where('source_path', $current)
                ->where('is_active', true);
            if ($exceptId !== null) {
                $query->where('id', '<>', $exceptId);
            }
            $next = $query->value('destination_path');
            if (! is_string($next)) {
                return;
            }
            $current = $next;
        }

        throw ValidationException::withMessages([
            'destinationPath' => 'Redirect chains are not allowed.',
        ]);
    }

    /** @param list<array{0: string, 1: string}> $paths */
    private function forgetAfterCommit(array $paths): void
    {
        $keys = array_values(array_unique(array_map(
            fn (array $path): string => $this->cacheKey($path[0], $path[1]),
            $paths,
        )));

        DB::afterCommit(static function () use ($keys): void {
            foreach ($keys as $key) {
                Cache::forget($key);
            }
        });
    }

    private function cacheKey(string $scope, string $path): string
    {
        return 'seo-redirect:'.hash('sha256', $scope).':'.hash('sha256', $path);
    }
}
