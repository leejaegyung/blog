<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    protected $fillable = [
        'post_id', 'keyword_project_id', 'purpose', 'provider', 'model',
        'prompt_version', 'input_tokens', 'output_tokens', 'cost', 'latency_ms',
        'status', 'error_message',
    ];

    protected function casts(): array
    {
        return ['cost' => 'decimal:6'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(KeywordProject::class, 'keyword_project_id');
    }
}
