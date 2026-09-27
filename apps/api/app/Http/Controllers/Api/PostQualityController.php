<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Services\AiWorker\AiWorkerUnavailableException;
use App\Services\QualityGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PostQualityController extends Controller
{
    public function __invoke(Post $post, QualityGate $gate): PostResource|JsonResponse
    {
        Gate::authorize('update', $post);

        if (empty($post->content_json['blocks'])) {
            throw ValidationException::withMessages(['content' => '검사할 본문이 없습니다. 초안을 먼저 만들어 주세요.']);
        }

        try {
            $gate->run($post);
        } catch (AiWorkerUnavailableException) {
            return response()->json(['message' => '품질 검사 서비스에 연결하지 못했습니다.'], 503);
        }

        return new PostResource($post->refresh()->load(['facts', 'images']));
    }
}
