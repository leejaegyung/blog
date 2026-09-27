<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Enums\Tone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    /** @use HasFactory<\Database\Factories\PostFactory> */
    use HasFactory;

    protected $fillable = [
        'keyword_project_id', 'title', 'tone', 'target_length',
        'content_json', 'content_html', 'content_text',
    ];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'tone' => Tone::class,
            'content_json' => 'array',
            'content_original_json' => 'array',
            'plan_json' => 'array',
            'draft_meta_json' => 'array',
            'quality_json' => 'array',
            'quality_checked_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(KeywordProject::class, 'keyword_project_id');
    }

    public function facts(): HasMany
    {
        return $this->hasMany(PostFact::class)->orderBy('sort_order');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PostImage::class)->orderBy('sort_order');
    }

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    public function publishJobs(): HasMany
    {
        return $this->hasMany(PublishJob::class);
    }
}
