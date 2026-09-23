<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ProductArchiveService
{
    public function archive(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $locked->status = 'inactive';
            $locked->save();
            $locked->delete();
        });
    }

    public function restore(int $productId): Product|string
    {
        return DB::transaction(function () use ($productId): Product|string {
            $product = Product::withTrashed()->whereKey($productId)->lockForUpdate()->first();
            if ($product === null) {
                throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
            }
            if (! $product->trashed()) {
                return 'product_not_archived';
            }

            $product->status = 'inactive';
            $product->published_at = null;
            $product->restore();

            return $product;
        });
    }
}
