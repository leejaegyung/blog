<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Enums\Tone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Post extends Model
{
    /** @use HasFactory<\Database\Factories\PostFactory> */
    use HasFactory;

    protected $fillable = [
        'keyword_project_id', 'platform', 'title', 'tone', 'target_length',
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
            'place_json' => 'array',
            'quality_checked_at' => 'datetime',
            'published_at' => 'datetime',
            'tistory_published_at' => 'datetime',
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

    /** 이 글과 같은 경험을 다른 플랫폼에 올리는 짝 글(이 글이 원래 글일 때) */
    public function twin(): HasOne
    {
        return $this->hasOne(Post::class, 'twin_of_post_id');
    }

    /** 이 글이 짝 글이면 원래 글 */
    public function twinOf(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'twin_of_post_id');
    }

    /** 원래 글이든 짝 글이든 짝이 되는 다른 글 */
    public function partner(): ?Post
    {
        return $this->twin_of_post_id ? $this->twinOf : $this->twin;
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
