<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\KeywordProject */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'keyword' => $this->keyword,
            'category' => $this->category,
            // keyword: 글마다 생기는 키워드, category: 관리 › 카테고리별 학습에서 만든 학습 카테고리
            'kind' => $this->kind,
            // 올릴 곳: naver | tistory (가이드·해시태그·학습 카테고리가 따로다)
            'platform' => $this->platform,
            'learning_category_id' => $this->learning_category_id,
            'learning_category' => $this->whenLoaded('learningCategory', fn () => $this->learningCategory?->only(['id', 'keyword'])),
            'status' => $this->status,
            'last_analyzed_at' => $this->last_analyzed_at,
            'analysis_version' => $this->analysis_version,
            // 사용자가 고친 해시태그(null이면 분석 추천을 쓴다)
            'hashtags' => $this->hashtags_json,
            'reference_count' => $this->whenCounted('references'),
            // 카테고리는 그 카테고리를 고른 키워드들로 쓴 글 수
            'post_count' => $this->kind === \App\Models\KeywordProject::KIND_CATEGORY
                ? $this->whenCounted('categoryPosts')
                : $this->whenCounted('posts'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
