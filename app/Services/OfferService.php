<?php

namespace App\Services;

use App\Exceptions\OfferProductConflictException;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OfferService
{
    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator */
    public function adminList(int $perPage = 20)
    {
        return Offer::query()
            ->with('products')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(array $data): Offer
    {
        return DB::transaction(function () use ($data): Offer {
            $offer = Offer::query()->create($this->offerAttributes($data));
            $this->replaceProductsAndValidate($offer, $data['product_ids'] ?? []);

            return $offer->load('products');
        });
    }

    public function update(Offer $offer, array $data): Offer
    {
        return DB::transaction(function () use ($offer, $data): Offer {
            $locked = Offer::query()->whereKey($offer->getKey())->lockForUpdate()->firstOrFail();
            $currentProductIds = $locked->products()->pluck('products.id')->map(fn ($id): int => (int) $id)->all();
            $productIds = array_key_exists('product_ids', $data)
                ? array_map('intval', $data['product_ids'])
                : $currentProductIds;

            $locked->fill($this->offerAttributes($data));
            $locked->save();
            $this->replaceProductsAndValidate($locked, $productIds, $currentProductIds);

            return $locked->load('products');
        });
    }

    public function deactivate(Offer $offer): Offer
    {
        return $this->update($offer, ['is_enabled' => false]);
    }

    /** @param array<string, mixed> $data */
    private function offerAttributes(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'name', 'discount_percentage', 'starts_at', 'ends_at', 'is_enabled',
        ]));
    }

    /** @param list<int> $productIds @param list<int> $previousProductIds */
    private function replaceProductsAndValidate(Offer $offer, array $productIds, array $previousProductIds = []): void
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        sort($productIds);
        $previousProductIds = array_values(array_unique(array_map('intval', $previousProductIds)));
        sort($previousProductIds);

        $products = Product::query()
            ->whereIn('id', array_values(array_unique([...$productIds, ...$previousProductIds])))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $selectedProducts = $products->whereIn('id', $productIds);
        if ($selectedProducts->count() !== count(array_unique($productIds))) {
            throw ValidationException::withMessages([
                'productIds' => ['One or more selected products are unavailable.'],
            ]);
        }

        if ((bool) $offer->is_enabled && $productIds === []) {
            throw ValidationException::withMessages([
                'productIds' => ['At least one product is required for an enabled offer.'],
            ]);
        }

        if ((bool) $offer->is_enabled) {
            $conflict = Offer::query()
                ->enabled()
                ->whereKeyNot($offer->getKey())
                ->where('starts_at', '<', $offer->ends_at)
                ->where('ends_at', '>', $offer->starts_at)
                ->whereHas('products', fn ($query) => $query->whereIn('products.id', $productIds))
                ->with(['products' => fn ($query) => $query->whereIn('products.id', $productIds)])
                ->orderBy('id')
                ->first();

            if ($conflict !== null) {
                $conflictingProductId = (int) $conflict->products->first()->getKey();
                throw new OfferProductConflictException($conflictingProductId, (int) $conflict->getKey());
            }
        }

        $offer->products()->sync($productIds);
    }
}
