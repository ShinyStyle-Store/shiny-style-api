<?php

namespace App\Models;

use App\Enums\MediaRole;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductReviewImage extends Model
{
    use SoftDeletes;

    protected $fillable = ['product_id', 'status', 'sort_order'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function mediaAttachment(): MorphOne
    {
        return $this->morphOne(MediaAttachment::class, 'mediable')
            ->where('role', MediaRole::CUSTOMER_REVIEW_IMAGE)
            ->with('mediaAsset');
    }

    public function mediaAttachments(): MorphMany
    {
        return $this->morphMany(MediaAttachment::class, 'mediable')
            ->where('role', MediaRole::CUSTOMER_REVIEW_IMAGE);
    }

    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', 'published');
    }
}
