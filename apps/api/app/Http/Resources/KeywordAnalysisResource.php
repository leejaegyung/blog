<?php

namespace App\Http\Resources;

use App\Jobs\AnalyzeKeywordJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\KeywordAnalysis */
class KeywordAnalysisResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $insight = $this->insight_json;

        return [
            'id' => $this->id,
            'primary_intent' => $this->primary_intent,
            'intent_distribution' => $insight['intent_distribution'] ?? [],
            'must_answer' => $this->must_answer_json ?? [],
            'recommended_outline' => $this->recommended_outline_json ?? [],
            'title_guidelines' => $this->title_patterns_json ?? [],
            'related_keywords' => $this->related_keywords_json ?? [],
            'writing_tips' => $insight['writing_tips'] ?? [],
            'stats' => $this->stats_json,
            // 검색 노출 가이드: 목표치(targets)·원칙(principles)·추천 해시태그(hashtags)·검사 기준(checks)
            'guide' => $this->guide_json,
            'insight_error' => $this->insight_error,
            'prompt_version' => $this->prompt_version,
            'analyzer_version' => $this->analyzer_version,
            // 분석 뒤에 참고자료나 키워드가 바뀌었으면 다시 분석하라고 알린다
            'stale' => $this->source_hash !== AnalyzeKeywordJob::sourceHash($this->project),
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
        ];
    }
}
