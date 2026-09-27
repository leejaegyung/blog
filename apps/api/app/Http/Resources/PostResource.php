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
            'title' => $this->title,
            'tone' => $this->tone,
            'target_length' => $this->target_length,
            'status' => $this->status,
            'published_url' => $this->published_url,
            'published_at' => $this->published_at,
            'plan' => $this->plan_json,
            'plan_error' => $this->plan_error,
            'content' => $this->content_json,
            'content_original' => $this->content_original_json,
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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
