<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishJob extends Model
{
    protected $fillable = ['publisher', 'status', 'attempt', 'error_message', 'finished_at'];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
