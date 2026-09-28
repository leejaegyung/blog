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
            'status' => $this->status,
            'last_analyzed_at' => $this->last_analyzed_at,
            'analysis_version' => $this->analysis_version,
            // 사용자가 고친 해시태그(null이면 분석 추천을 쓴다)
            'hashtags' => $this->hashtags_json,
            'reference_count' => $this->whenCounted('references'),
            'post_count' => $this->whenCounted('posts'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
