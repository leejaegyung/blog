<?php

namespace App\Models;

use App\Enums\ParseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReferenceDocument extends Model
{
    protected $fillable = [
        'source_type', 'source_url', 'title', 'author', 'published_at',
        'raw_storage_key', 'content_hash', 'usage_permission', 'parse_status',
        'error_message', 'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'collected_at' => 'datetime',
            'parse_status' => ParseStatus::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(KeywordProject::class, 'keyword_project_id');
    }

    public function features(): HasOne
    {
        return $this->hasOne(DocumentFeature::class);
    }
}
