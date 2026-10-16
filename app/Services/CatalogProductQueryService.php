<?php

namespace App\Services;

use App\Exceptions\OfferPricingInvariantException;
use App\Support\ExactMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CatalogProductQueryService
{
    public function __construct(private readonly OfferPricingService $pricing) {}

    /**
     * Add the selected public variant and exact effective display price to a catalog query.
     *
     * @param  array<string, mixed>  $filters
     */
    public function apply(Builder $query, array $filters, CarbonImmutable $instant): Builder
    {
        $query->addSelect(['catalog_display_variant_id' => $this->displayVariantQuery()]);

        $sort = $filters['sort'] ?? 'newest';
        $needsPriceProjection = isset($filters['min_price'])
            || isset($filters['max_price'])
            || $sort === 'price_asc'
            || $sort === 'price_desc';

        if ($needsPriceProjection) {
            $this->assertNoMultipleQualifyingOffers($query, $instant);
            $query->addSelect(['catalog_effective_price_minor' => $this->effectivePriceQuery($instant)]);
        }

        if (($filters['offer'] ?? null) === 'active') {
            $query->whereExists($this->qualifyingOfferLinks($instant)
                ->whereColumn('offer_product.product_id', 'products.id')
                ->selectRaw('1'));
        }

        if (isset($filters['min_price'])) {
            $this->whereEffectivePrice($query, '>=', ExactMoney::toMinorUnitInteger($filters['min_price']), $instant);
        }

        if (isset($filters['max_price'])) {
            $this->whereEffectivePrice($query, '<=', ExactMoney::toMinorUnitInteger($filters['max_price']), $instant);
        }

        if ($sort === 'price_asc' || $sort === 'price_desc') {
            $query->orderBy('catalog_effective_price_minor', $sort === 'price_asc' ? 'asc' : 'desc');
        }

        return $query->orderByDesc('published_at')->orderByDesc('products.id');
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function displayVariantQuery(): \Illuminate\Database\Query\Builder
    {
        return $this->displayVariantSelectionQuery()->select('catalog_variant.id');
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function effectivePriceQuery(CarbonImmutable $instant): \Illuminate\Database\Query\Builder
    {
        return $this->displayVariantSelectionQuery()->selectRaw(
            'CASE WHEN ('.$this->offerPercentageQuery($instant)->toSql().') IS NULL '
                .'THEN '.self::baseMinorSql().' '
                .'ELSE CASE WHEN '.self::baseMinorSql().' > 0 AND ('.self::baseMinorSql().' - '.self::discountMinorSql($this->offerPercentageQuery($instant)->toSql()).') < 1 '
                .'THEN 1 ELSE '.self::baseMinorSql().' - '.self::discountMinorSql($this->offerPercentageQuery($instant)->toSql()).' END END AS catalog_effective_price_minor',
            $this->effectivePriceBindings($instant),
        );

    }

    private function whereEffectivePrice(Builder $query, string $operator, int $minorUnits, CarbonImmutable $instant): void
    {
        $price = $this->effectivePriceQuery($instant);
        $query->whereRaw('('.$price->toSql().') '.$operator.' ?', [...$price->getBindings(), $minorUnits]);
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function offerPercentageQuery(CarbonImmutable $instant): \Illuminate\Database\Query\Builder
    {
        return $this->pricing->qualifyingOffers($instant)
            ->join('offer_product', 'offers.id', '=', 'offer_product.offer_id')
            ->whereColumn('offer_product.product_id', 'catalog_variant.product_id')
            ->select('offers.discount_percentage')
            ->orderBy('offers.id')
            ->limit(1)
            ->toBase();
    }

    /** @return list<mixed> */
    private function effectivePriceBindings(CarbonImmutable $instant): array
    {
        $offerBindings = $this->offerPercentageQuery($instant)->getBindings();

        return [...$offerBindings, ...$offerBindings, ...$offerBindings];
    }

    private static function baseMinorSql(): string
    {
        return 'CAST(ROUND(catalog_variant.price * 100, 0) AS BIGINT)';
    }

    private static function discountMinorSql(string $offerQuerySql): string
    {
        $basisPoints = 'CAST(ROUND(('.$offerQuerySql.') * 100, 0) AS BIGINT)';

        return '(('.self::baseMinorSql().' * '.$basisPoints.' + 5000) / 10000)';
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function eligibleVariantsQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('sellable_items as catalog_variant')
            ->whereColumn('catalog_variant.product_id', 'products.id')
            ->where('catalog_variant.status', 'active')
            ->whereNull('catalog_variant.deleted_at')
            ->whereNull('catalog_variant.superseded_at');
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function displayVariantSelectionQuery(): \Illuminate\Database\Query\Builder
    {
        return $this->eligibleVariantsQuery()
            ->orderByRaw(
                'CASE '
                    .'WHEN catalog_variant.is_default = true AND catalog_variant.stock_quantity > catalog_variant.reserved_quantity THEN 0 '
                    .'WHEN catalog_variant.stock_quantity > catalog_variant.reserved_quantity THEN 1 '
                    .'WHEN catalog_variant.is_default = true THEN 2 '
                    .'ELSE 3 END',
            )
            ->orderBy('catalog_variant.id')
            ->limit(1);
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function qualifyingOfferLinks(CarbonImmutable $instant): \Illuminate\Database\Query\Builder
    {
        return DB::table('offer_product')
            ->joinSub(
                $this->pricing->qualifyingOffers($instant)->select('offers.id as qualifying_offer_id'),
                'qualifying_offers',
                fn (JoinClause $join) => $join->on('qualifying_offers.qualifying_offer_id', '=', 'offer_product.offer_id'),
            );
    }

    private function assertNoMultipleQualifyingOffers(Builder $products, CarbonImmutable $instant): void
    {
        $candidateProducts = (clone $products)->reorder()->select('products.id');
        $links = $this->qualifyingOfferLinks($instant)
            ->joinSub($candidateProducts->toBase(), 'catalog_candidates', function (JoinClause $join): void {
                $join->on('catalog_candidates.id', '=', 'offer_product.product_id');
            });
        $conflict = $links->select('offer_product.product_id')
            ->selectRaw('COUNT(*) as qualifying_offer_count')
            ->groupBy('offer_product.product_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('offer_product.product_id')
            ->first();

        if ($conflict === null) {
            return;
        }

        $offerIds = $this->qualifyingOfferLinks($instant)
            ->where('offer_product.product_id', $conflict->product_id)
            ->orderBy('offer_product.offer_id')
            ->pluck('offer_product.offer_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        Log::error('Offer pricing invariant violated: multiple qualifying offers.', [
            'product_id' => (int) $conflict->product_id,
            'offer_ids' => $offerIds,
            'at' => $instant->toISOString(),
        ]);

        throw new OfferPricingInvariantException((int) $conflict->product_id, $offerIds);
    }
}
