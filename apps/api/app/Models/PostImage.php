<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostImage extends Model
{
    protected $fillable = [
        'storage_key', 'thumb_key', 'original_name', 'mime_type', 'width', 'height',
        'size_bytes', 'taken_at', 'sort_order', 'vision_json', 'vision_status', 'vision_error', 'caption', 'source_image_id',
    ];

    protected function casts(): array
    {
        return [
            'vision_json' => 'array',
            'taken_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
