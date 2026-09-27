<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Blog extends Model
{
    protected $fillable = ['platform', 'blog_name', 'blog_url', 'publish_mode', 'status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
