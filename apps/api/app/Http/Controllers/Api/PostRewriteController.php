<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\AiWorkerUnavailableException;
use App\Services\AiWorker\GenerationRecorder;
use App\Services\AiWorker\LlmStopException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** 편집기에서 선택한 문단 하나를 다시 쓴다(짧게·길게·자연스럽게·다시). 짧은 작업이라 동기로 처리한다. */
class PostRewriteController extends Controller
{
    public function __invoke(Request $request, Post $post, AiWorkerClient $worker, GenerationRecorder $recorder): JsonResponse
    {
        Gate::authorize('update', $post);

        $data = $request->validate([
            'text' => ['required', 'string', 'max:3000'],
            'instruction' => ['required', 'in:shorter,longer,natural,rewrite'],
            'before' => ['nullable', 'string', 'max:3000'],
            'after' => ['nullable', 'string', 'max:3000'],
        ]);

        try {
            $result = $worker->rewriteParagraph([
                ...$data,
                'tone' => $post->tone?->value ?? 'natural',
                'speech' => $post->speech ?? 'auto',
                'facts' => $post->facts()->get(['fact_key', 'fact_value'])->toArray(),
                'forbidden_claims' => $post->plan_json['forbidden_claims'] ?? [],
            ]);
        } catch (LlmStopException $e) {
            // 시간 초과·시간당 한도: 이유를 그대로 알려 준다(다시 누르기 전에 알 수 있게)
            return response()->json(['message' => $e->getMessage()], 429);
        } catch (AiWorkerUnavailableException) {
            return response()->json(['message' => 'AI 서비스에 연결하지 못했습니다.'], 503);
        }

        $recorder->record($result['generations'], purpose: 'rewrite', promptVersion: $result['prompt_version'], post: $post);

        if ($result['text'] === null) {
            return response()->json(['message' => $result['error']], 503);
        }

        return response()->json(['text' => $result['text'], 'warnings' => $result['warnings']]);
    }
}
