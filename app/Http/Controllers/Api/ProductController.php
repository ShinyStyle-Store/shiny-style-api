<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductDetailResource;
use App\Http\Resources\ProductListResource;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Product::query()->visible();
        $this->applySearch($query, $this->searchWords($validated['q'] ?? null));
        $category = trim($validated['category'] ?? '');

        if ($category !== '') {
            $query->whereHas('categories', function (Builder $query) use ($category): void {
                $query->active()->where('slug', $category);
            });
        }

        $products = $query
            ->with($this->listingRelations())
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 24)
            ->withQueryString();

        return ProductListResource::collection($products);
    }

    private function searchWords(?string $search): array
    {
        if ($search === null) {
            return [];
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($search));

        if ($normalized === '' || $normalized === null) {
            return [];
        }

        return array_values(array_filter(
            explode(' ', Str::lower($normalized)),
            static fn (string $word): bool => $word !== '',
        ));
    }

    private function applySearch(Builder $query, array $words): void
    {
        $operator = $query->getConnection()->getDriverName() === 'pgsql'
            ? 'ILIKE'
            : 'LIKE';

        foreach ($words as $word) {
            $escapedWord = str_replace(
                ['\\', '%', '_'],
                ['\\\\', '\\%', '\\_'],
                $word,
            );
            $pattern = "%{$escapedWord}%";

            $query->where(function (Builder $query) use ($operator, $pattern): void {
                $query->whereRaw("name_ar {$operator} ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("name_en {$operator} ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("slug {$operator} ? ESCAPE '\\'", [$pattern]);
            });
        }
    }

    public function featured()
    {
        $products = Product::query()
            ->visible()
            ->featured()
            ->with($this->listingRelations())
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return ProductListResource::collection($products);
    }

    public function show(string $slug)
    {
        $product = Product::query()
            ->visible()
            ->where('slug', $slug)
            ->with([
                ...$this->listingRelations(),
                'options' => fn ($query) => $query->ordered(),
                'options.values' => fn ($query) => $query->ordered(),
                'sellableItems' => fn ($query) => $query->active()->orderBy('id'),
                'sellableItems.optionValues.option',
                'media' => fn ($query) => $this->publicMediaQuery($query)->ordered(),
            ])
            ->firstOrFail();

        return new ProductDetailResource($product);
    }

    private function listingRelations(): array
    {
        return [
            'categories' => fn ($query) => $query->active()->ordered(),
            'sellableItems' => fn ($query) => $query->active()->orderBy('id'),
            'media' => fn ($query) => $this->publicMediaQuery($query)->images()->ordered(),
        ];
    }

    private function publicMediaQuery(HasMany $query): HasMany
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereNull('sellable_item_id')
                ->orWhereHas('sellableItem', fn (Builder $query) => $query->active());
        });
    }
}
