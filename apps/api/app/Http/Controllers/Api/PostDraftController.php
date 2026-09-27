<?php

namespace App\Http\Controllers\Api;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Jobs\GenerateDraftJob;
use App\Models\Post;
use App\Support\PlanIntegrity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PostDraftController extends Controller
{
    public function store(Post $post): JsonResponse
    {
        Gate::authorize('update', $post);

        $post->load(['facts', 'images']);
        $respond = fn () => (new PostResource($post))->response()->setStatusCode(202);

        if ($post->status === PostStatus::Generating) {
            return $respond();
        }
        if ($post->status === PostStatus::Planning) {
            throw ValidationException::withMessages(['plan' => '글 계획을 만드는 중입니다. 끝난 뒤 다시 시도해 주세요.']);
        }
        if (! $post->plan_json) {
            throw ValidationException::withMessages(['plan' => '먼저 글 계획을 만들어 주세요.']);
        }
        if (PlanIntegrity::isStale($post->plan_json, $post)) {
            throw ValidationException::withMessages(['plan' => '계획을 만든 뒤 사실이나 사진이 바뀌었습니다. 계획을 다시 만들어 주세요.']);
        }

        $post->forceFill(['status' => PostStatus::Generating, 'draft_error' => null])->save();
        GenerateDraftJob::dispatch($post);

        return $respond();
    }
}
