<?php

namespace App\Http\Resources;

use App\Services\QualityGate;
use App\Support\PlanIntegrity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Post */
class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'keyword_project_id' => $this->keyword_project_id,
            'keyword' => $this->whenLoaded('project', fn () => $this->project?->keyword),
            // 이 글의 키워드가 쓰는 학습 카테고리(글 하나를 불러올 때만)
            'learning_category' => $this->when(
                $this->relationLoaded('project') && $this->project?->relationLoaded('learningCategory'),
                fn () => $this->project?->learningCategory?->only(['id', 'keyword']),
            ),
            'title' => $this->title,
            'tone' => $this->tone,
            'target_length' => $this->target_length,
            'status' => $this->status,
            'pipeline_status' => $this->pipeline_status,
            'pipeline_step' => $this->pipeline_step,
            'pipeline_error' => $this->pipeline_error,
            'platform' => $this->platform,
            'published_url' => $this->published_url,
            'tistory_url' => $this->tistory_url,
            'tistory_published_at' => $this->tistory_published_at,
            'published_at' => $this->published_at,
            'plan' => $this->plan_json,
            'plan_error' => $this->plan_error,
            'content' => $this->content_json,
            'content_original' => $this->content_original_json,
            // 이 키워드로 쓸 때 다는 해시태그(프로젝트와 최근 분석을 함께 불러온 응답에만)
            'recommended_hashtags' => $this->when(
                $this->relationLoaded('project') && $this->project?->relationLoaded('latestAnalysis'),
                fn () => $this->project?->hashtags() ?? [],
            ),
            'draft_meta' => $this->draft_meta_json,
            'draft_error' => $this->draft_error,
            'quality' => $this->quality_json ? collect($this->quality_json)->except('source_hash')->all() : null,
            'quality_checked_at' => $this->quality_checked_at,
            'quality_stale' => $this->quality_json && $this->relationLoaded('facts') && $this->relationLoaded('images')
                ? ($this->quality_json['source_hash'] ?? null) !== QualityGate::sourceHash($this->resource)
                : null,
            'plan_stale' => $this->relationLoaded('facts') && $this->relationLoaded('images')
                ? PlanIntegrity::isStale($this->plan_json, $this->resource)
                : null,
            'facts' => $this->whenLoaded('facts', fn () => $this->facts->map(fn ($fact) => [
                'id' => $fact->id,
                'fact_key' => $fact->fact_key,
                'fact_value' => $fact->fact_value,
            ])),
            'images' => PostImageResource::collection($this->whenLoaded('images')),
            'image_count' => $this->whenCounted('images'),
            'fact_count' => $this->whenCounted('facts'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
