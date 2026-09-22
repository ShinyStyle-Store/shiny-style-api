<?php

namespace App\Models;

use App\Enums\MediaRole;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Banner extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title_ar', 'title_en', 'description_ar', 'description_en',
        'cta_text_ar', 'cta_text_en', 'cta_type', 'cta_target', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function mediaAttachments(): MorphMany
    {
        return $this->morphMany(MediaAttachment::class, 'mediable')
            ->where('role', MediaRole::BANNER_IMAGE);
    }

    public function bannerImageAttachment(): MorphOne
    {
        return $this->morphOne(MediaAttachment::class, 'mediable')
            ->where('role', MediaRole::BANNER_IMAGE)
            ->where('is_primary', true)
            ->whereHas('mediaAsset', fn (Builder $asset) => $asset->where('media_type', 'image'));
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->active()->whereHas('bannerImageAttachment');
    }

    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }
}
