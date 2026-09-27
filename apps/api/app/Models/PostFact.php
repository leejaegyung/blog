<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostFact extends Model
{
    protected $fillable = ['fact_key', 'fact_value', 'source_type', 'verified', 'sort_order'];

    protected function casts(): array
    {
        return ['verified' => 'boolean'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
