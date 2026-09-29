<?php

namespace App\Services;

use App\Exceptions\OfferPricingInvariantException;
use App\Models\Offer;
use App\Models\Product;
use App\Models\SellableItem;
use App\Support\ExactMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class OfferPricingService
{
    /**
     * Calculate one variant using one captured instant.
     *
     * The caller is responsible for catalog category visibility. This service
     * enforces active/non-archived variant and product visibility basics.
     *
     * @return array<string, mixed>
     */
    public function calculate(SellableItem $item, ?CarbonImmutable $at = null): array
    {
        return $this->calculateMany(new EloquentCollection([$item]), $at)->first();
    }

    /**
     * Calculate variants in one batch. Offers and products are loaded in bulk,
     * so the method does not issue a query per variant.
     *
     * @param EloquentCollection<int, SellableItem> $items
     * @return Collection<int, array<string, mixed>>
     */
    public function calculateMany(EloquentCollection $items, ?CarbonImmutable $at = null): Collection
    {
        $instant = ($at ?? CarbonImmutable::now())->utc();
        $items = $items->values();
        $productIds = $items->pluck('product_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();

        $products = $productIds->isEmpty()
            ? collect()
            : \App\Models\Product::withTrashed()
                ->whereIn('id', $productIds)
                ->get(['id', 'status', 'published_at', 'deleted_at'])
                ->keyBy('id');

        $eligibleItems = $items->filter(function (SellableItem $item) use ($products, $instant): bool {
            $product = $products->get($item->product_id);

            return $item->status === 'active'
                && $item->deleted_at === null
                && $product !== null
                && $product->deleted_at === null
                && $product->status === 'active'
                && $product->published_at !== null
                && ! $product->published_at->greaterThan($instant);
        });

        $eligibleProductIds = $eligibleItems->pluck('product_id')->unique()->values();
        $offersByProduct = collect();

        if ($eligibleProductIds->isNotEmpty()) {
            $offers = Offer::query()
                ->enabled()
                ->where('starts_at', '<=', $instant)
                ->where('ends_at', '>', $instant)
                ->whereHas('products', fn ($query) => $query->whereIn('products.id', $eligibleProductIds))
                ->with(['products' => fn ($query) => $query->whereIn('products.id', $eligibleProductIds)])
                ->orderBy('id')
                ->get();

            foreach ($offers as $offer) {
                foreach ($offer->products as $product) {
                    $existing = $offersByProduct->get((int) $product->getKey(), collect());
                    $offersByProduct->put((int) $product->getKey(), $existing->push($offer));
                }
            }
        }

        foreach ($offersByProduct->sortKeys() as $productId => $productOffers) {
            if ($productOffers->count() > 1) {
                $offerIds = $productOffers->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
                Log::error('Offer pricing invariant violated: multiple qualifying offers.', [
                    'product_id' => (int) $productId,
                    'offer_ids' => $offerIds,
                    'at' => $instant->toISOString(),
                ]);
                throw new OfferPricingInvariantException((int) $productId, $offerIds);
            }
        }

        return $items->map(function (SellableItem $item) use ($eligibleItems, $offersByProduct): array {
            $baseMinor = $this->minorPrice($item);
            $eligible = $eligibleItems->contains(fn (SellableItem $eligibleItem): bool => $eligibleItem->is($item));
            $offer = $eligible ? $offersByProduct->get((int) $item->product_id)?->first() : null;

            if ($offer === null) {
                return $this->result($item, $baseMinor, $baseMinor, null, $eligible);
            }

            $percentageBasisPoints = $this->percentageBasisPoints((string) $offer->discount_percentage);
            $discountMinor = $this->roundHalfUp($baseMinor * $percentageBasisPoints, 10000);
            $effectiveMinor = $baseMinor - $discountMinor;

            if ($baseMinor > 0 && $effectiveMinor < 1) {
                $effectiveMinor = 1;
                $discountMinor = $baseMinor - $effectiveMinor;
            }

            return $this->result($item, $baseMinor, $effectiveMinor, $offer, true, $discountMinor);
        })->values();
    }

    /**
     * Attach batch pricing to products whose sellableItems relation is already loaded.
     * Callers must still apply their own category visibility query before loading products.
     *
     * @param Collection<int, Product> $products
     * @return Collection<int, Product>
     */
    public function attachToProducts(Collection $products, ?CarbonImmutable $at = null): Collection
    {
        $items = $products->flatMap(function (Product $product): Collection {
            if (! $product->relationLoaded('sellableItems')) {
                throw new InvalidArgumentException('Products must eager load sellableItems before pricing.');
            }

            return $product->sellableItems;
        })->values();

        $pricing = $this->calculateMany(new EloquentCollection($items->all()), $at)
            ->keyBy(fn (array $result): int => (int) $result['sellableItemId']);

        return $products->each(function (Product $product) use ($pricing): void {
            $product->setAttribute('offerPricing', $product->sellableItems
                ->mapWithKeys(fn (SellableItem $item): array => [
                    (int) $item->getKey() => $pricing->get((int) $item->getKey()),
                ])->all());
        });
    }

    /** @return array<string, mixed> */
    private function result(
        SellableItem $item,
        int $baseMinor,
        int $effectiveMinor,
        ?Offer $offer,
        bool $eligible,
        ?int $discountMinor = null,
    ): array {
        return [
            'sellableItemId' => $item->getKey(),
            'productId' => $item->product_id,
            'eligible' => $eligible,
            'basePrice' => ExactMoney::formatMinorUnits((string) $baseMinor),
            'effectivePrice' => ExactMoney::formatMinorUnits((string) $effectiveMinor),
            'discountAmount' => ExactMoney::formatMinorUnits((string) ($discountMinor ?? 0)),
            'offerApplied' => $offer !== null,
            'offerId' => $offer?->getKey(),
            'discountPercentage' => $offer === null ? null : (string) $offer->discount_percentage,
        ];
    }

    private function minorPrice(SellableItem $item): int
    {
        try {
            return ExactMoney::toMinorUnitInteger((string) $item->price);
        } catch (InvalidArgumentException | \OverflowException $exception) {
            throw new InvalidArgumentException('The sellable item price is invalid.', 0, $exception);
        }
    }

    private function percentageBasisPoints(string $percentage): int
    {
        try {
            $basisPoints = ExactMoney::toMinorUnitInteger($percentage);
        } catch (InvalidArgumentException | \OverflowException $exception) {
            throw new InvalidArgumentException('The offer percentage is invalid.', 0, $exception);
        }

        if ($basisPoints < 1 || $basisPoints >= 10000) {
            throw new InvalidArgumentException('The offer percentage is outside the supported range.');
        }

        return $basisPoints;
    }

    private function roundHalfUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
