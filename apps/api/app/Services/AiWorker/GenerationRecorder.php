<?php

namespace App\Services\AiWorker;

use App\Models\Generation;
use App\Models\KeywordProject;
use App\Models\Post;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * AI Worker가 돌려준 LLM 호출 기록(성공·실패 시도 전부)을 generations 테이블에 남긴다.
 */
class GenerationRecorder
{
    /**
     * @param  list<array{provider: string, model: string, status: string, input_tokens?: int, output_tokens?: int, latency_ms: int, error_kind?: ?string, error_message?: ?string}>  $metas
     * @return Collection<int, Generation>
     */
    public function record(
        array $metas,
        string $purpose,
        ?string $promptVersion = null,
        ?Post $post = null,
        ?KeywordProject $project = null,
    ): Collection {
        foreach ($metas as $meta) {
            // 구조화 로그(기획서 23장): 프롬프트·사실 등 사용자 내용은 남기지 않는다
            Log::info('llm_call', [
                'purpose' => $purpose,
                'provider' => $meta['provider'],
                'model' => $meta['model'],
                'status' => $meta['status'],
                'error_kind' => $meta['error_kind'] ?? null,
                'latency_ms' => $meta['latency_ms'],
                'input_tokens' => $meta['input_tokens'] ?? 0,
                'output_tokens' => $meta['output_tokens'] ?? 0,
                'prompt_version' => $promptVersion,
                'post_id' => $post?->id,
                'keyword_project_id' => $project?->id ?? $post?->keyword_project_id,
            ]);
        }

        return collect($metas)->map(fn (array $meta) => Generation::create([
            'post_id' => $post?->id,
            'keyword_project_id' => $project?->id ?? $post?->keyword_project_id,
            'purpose' => $purpose,
            'provider' => $meta['provider'],
            'model' => $meta['model'],
            'prompt_version' => $promptVersion,
            'input_tokens' => $meta['input_tokens'] ?? 0,
            'output_tokens' => $meta['output_tokens'] ?? 0,
            'latency_ms' => $meta['latency_ms'],
            'status' => $meta['status'],
            'error_message' => isset($meta['error_kind'])
                ? trim($meta['error_kind'].': '.($meta['error_message'] ?? ''))
                : null,
        ]));
    }
}
