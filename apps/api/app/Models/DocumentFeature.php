<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentFeature extends Model
{
    protected $fillable = [
        'char_count', 'paragraph_count', 'image_count', 'keyword_frequency',
        'features_json', 'analyzer_version',
    ];

    protected function casts(): array
    {
        return ['features_json' => 'array'];
    }

    public function reference(): BelongsTo
    {
        return $this->belongsTo(ReferenceDocument::class, 'reference_document_id');
    }
}
