<?php

namespace App\Models;

use App\Enums\MediaRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

class MediaAttachment extends Model
{
    protected $fillable = [
        'media_asset_id',
        'role',
        'locale',
        'device',
        'alt_ar',
        'alt_en',
        'caption_ar',
        'caption_en',
        'sort_order',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_primary' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $attachment): void {
            if ((int) $attachment->sort_order < 0) {
                throw new InvalidArgumentException('Media attachment sort order cannot be negative.');
            }

            $owner = Relation::getMorphedModel((string) $attachment->mediable_type);
            $role = (string) $attachment->role;

            if (! is_string($owner) || ! MediaRole::supports($owner, $role)) {
                throw new InvalidArgumentException('The media role is not supported for this owner type.');
            }

            $asset = $attachment->mediaAsset()->first();
            if ($asset === null || $asset->media_type !== MediaRole::mediaType($role)) {
                throw new InvalidArgumentException('The media asset type does not match the attachment role.');
            }
        });
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }
}
