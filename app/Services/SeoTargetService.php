<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class SeoTargetService
{
    public function findManageable(string $type, int $id): Model
    {
        return match ($type) {
            'product' => Product::query()->findOrFail($id),
            'category' => Category::query()->findOrFail($id),
            'content_page' => ContentPage::query()
                ->whereIn('slug', config('content.page_slugs', []))
                ->findOrFail($id),
            default => throw (new ModelNotFoundException)->setModel(Model::class, [$type, $id]),
        };
    }
}
