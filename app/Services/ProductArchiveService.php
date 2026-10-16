<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ProductArchiveService
{
    public function __construct(private readonly SeoRedirectService $redirects) {}

    public function archive(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $wasPublic = $this->redirects->isProductPubliclyVisible((int) $locked->getKey());
            $locked->status = 'inactive';
            $locked->save();
            $locked->delete();

            if ($wasPublic) {
                app(SeoRevalidationService::class)->afterCommitForEntity(
                    $locked,
                    'archived',
                    ['ar', 'en'],
                    ['catalog', 'sitemap', 'metaFeed', 'redirects'],
                );
            }
        });
    }

    public function restore(int $productId): Product|string
    {
        $transaction = fn (): Product|string => DB::transaction(function () use ($productId): Product|string {
            $product = Product::withTrashed()->whereKey($productId)->lockForUpdate()->first();
            if ($product === null) {
                throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
            }
            if (! $product->trashed()) {
                return 'product_not_archived';
            }
            if ($this->redirects->slugPathIsReserved('product', (string) $product->slug)) {
                return 'product_redirect_conflict';
            }

            $product->status = 'inactive';
            $product->published_at = null;
            $product->restore();

            return $product;
        });

        return $this->redirects->withMutationLock($transaction);
    }
}
