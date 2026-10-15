<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SeoRedirect extends Model
{
    protected $fillable = [
        'domain_scope',
        'source_path',
        'source_hash',
        'destination_path',
        'status_code',
        'is_active',
        'reason',
        'entity_type',
        'entity_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'is_active' => 'boolean',
            'entity_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeForSource(Builder $query, string $domainScope, string $sourcePath): void
    {
        $query->where('domain_scope', $domainScope)
            ->where('source_hash', hash('sha256', $sourcePath))
            ->where('source_path', $sourcePath);
    }
}
