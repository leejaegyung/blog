<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Jobs\StartTwinJob;
use App\Models\KeywordProject;
use App\Models\Post;
use App\Services\TwinPosts;
use App\Support\Platform;
use App\Support\TextOverlap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** 짝 글(같은 경험을 다른 플랫폼에 따로 쓴 글): 6단계에서 보고, 만들고, 다시 쓴다 */
class PostTwinController extends Controller
{
    /** 짝 글과 두 글이 얼마나 겹치는지(중복 문서 확인) */
    public function show(Post $post): JsonResponse
    {
        Gate::authorize('view', $post);
        $partner = $post->partner();

        return response()->json([
            'data' => $partner ? new PostResource($partner->load(['facts', 'images'])) : null,
            'overlap' => $partner && $post->content_text && $partner->content_text
                ? TextOverlap::ratio($post->content_text, $partner->content_text)
                : null,
        ]);
    }

    /** 짝 글을 만들고(없으면) 원래 글의 사실·사진을 가져와 그 플랫폼 기준으로 초안까지 쓴다 */
    public function store(Request $request, Post $post, TwinPosts $twins): JsonResponse
    {
        Gate::authorize('update', $post);
        if ($post->twin_of_post_id) {
            throw ValidationException::withMessages(['post' => '짝 글은 원래 글 6단계에서 다시 쓸 수 있어요.']);
        }
        $data = $request->validate(['learning_category_id' => ['nullable', 'integer']]);
        if ($post->facts()->doesntExist()) {
            throw ValidationException::withMessages(['facts' => '알려주고 싶은 내용을 1개 이상 입력해 주세요.']);
        }

        $platform = TwinPosts::otherPlatform($post->platform);
        $learning = null;
        if (! empty($data['learning_category_id'])) {
            $learning = $request->user()->keywordProjects()
                ->where('kind', KeywordProject::KIND_CATEGORY)->where('platform', $platform)
                ->find($data['learning_category_id']);
            if (! $learning) {
                throw ValidationException::withMessages(['learning_category_id' => Platform::label($platform).'용 학습 카테고리를 찾을 수 없어요.']);
            }
        }

        $twin = $twins->ensure($post, $learning);
        if ($twin->pipeline_status !== 'running') {
            // 원래 글 사진 분석이 아직이면 그 흐름 안에서 짝 글이 시작된다. 아니면 바로 시작
            StartTwinJob::dispatch($post->refresh(), force: true);
        }

        return (new PostResource($twin->refresh()->load(['facts', 'images'])))->response()->setStatusCode(202);
    }
}
