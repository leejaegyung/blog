<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class KeywordProject extends Model
{
    /** @use HasFactory<\Database\Factories\KeywordProjectFactory> */
    use HasFactory;

    protected $fillable = ['keyword', 'category'];

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
        return $this->hashtags_json
            ?? collect($this->latestAnalysis?->guide_json['hashtags'] ?? [])->pluck('tag')->values()->all();
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

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
