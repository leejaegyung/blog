<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class KeywordProject extends Model
{
    /** @use HasFactory<\Database\Factories\KeywordProjectFactory> */
    use HasFactory;

    public const KIND_KEYWORD = 'keyword';

    // 사용자가 만들고 참고 글 URL로 학습시키는 카테고리(이름은 keyword 칸에 둔다)
    public const KIND_CATEGORY = 'category';

    protected $fillable = ['keyword', 'category', 'kind', 'learning_category_id'];

    protected $attributes = ['kind' => self::KIND_KEYWORD];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'last_analyzed_at' => 'datetime',
            'hashtags_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function references(): HasMany
    {
        return $this->hasMany(ReferenceDocument::class);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(KeywordAnalysis::class);
    }

    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(KeywordAnalysis::class)->latestOfMany();
    }

    /**
     * 글에 달 해시태그: 사용자가 고친 목록이 있으면 그것, 없으면 최근 분석의 추천.
     *
     * @return list<string>
     */
    public function hashtags(): array
    {
        if ($this->hashtags_json !== null) {
            return $this->hashtags_json;
        }
        $own = collect($this->latestAnalysis?->guide_json['hashtags'] ?? [])->pluck('tag')->all();
        // 학습 카테고리를 골랐으면 그 카테고리의 해시태그(직접 고친 것 또는 학습 추천)도 붙인다
        $learned = ! $this->isCategory() && $this->learningCategory ? $this->learningCategory->hashtags() : [];

        return self::normalizeHashtags([...$own, ...$learned]);
    }

    /** 해시태그를 네이버 태그 모양으로: # 떼고, 띄어쓰기·기호 없이, 중복 없이, 30개까지 */
    public static function normalizeHashtags(array $tags): array
    {
        return collect($tags)
            ->map(fn ($tag) => preg_replace('/[^0-9A-Za-z가-힣_]/u', '', ltrim(trim((string) $tag), '#')))
            ->filter(fn ($tag) => $tag !== '' && ! ctype_digit($tag) && mb_strlen($tag) <= 30)
            ->unique(fn ($tag) => mb_strtolower($tag))
            ->take(30)
            ->values()
            ->all();
    }

    /** 키워드가 고른 학습 카테고리 */
    public function learningCategory(): BelongsTo
    {
        return $this->belongsTo(self::class, 'learning_category_id');
    }

    /** 학습 카테고리를 고른 키워드들 */
    public function keywords(): HasMany
    {
        return $this->hasMany(self::class, 'learning_category_id');
    }

    /** 학습 카테고리로 쓴 글(키워드를 거쳐) */
    public function categoryPosts(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, self::class, 'learning_category_id', 'keyword_project_id');
    }

    public function isCategory(): bool
    {
        return $this->kind === self::KIND_CATEGORY;
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
