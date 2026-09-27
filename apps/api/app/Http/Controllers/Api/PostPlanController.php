<?php

namespace App\Http\Controllers\Api;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Jobs\GeneratePlanJob;
use App\Models\Post;
use App\Support\PlanIntegrity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PostPlanController extends Controller
{
    public function store(Post $post): JsonResponse
    {
        Gate::authorize('update', $post);

        if ($post->status === PostStatus::Planning) {
            return (new PostResource($post->load(['facts', 'images'])))->response()->setStatusCode(202);
        }
        if ($post->facts()->doesntExist()) {
            throw ValidationException::withMessages(['facts' => '알려주고 싶은 내용을 1개 이상 입력하고 저장해 주세요.']);
        }
        if (! $post->keyword_project_id) {
            throw ValidationException::withMessages(['post' => '프로젝트에 속한 글만 계획을 만들 수 있습니다.']);
        }

        $post->forceFill(['status' => PostStatus::Planning, 'plan_error' => null])->save();
        GeneratePlanJob::dispatch($post);

        return (new PostResource($post->load(['facts', 'images'])))->response()->setStatusCode(202);
    }

    /** 사용자가 고른 제목과 고친 목차를 저장한다. 사실 항목·사진은 이 글의 것만 허용한다. */
    public function update(Request $request, Post $post): PostResource
    {
        Gate::authorize('update', $post);

        if (! $post->plan_json) {
            throw ValidationException::withMessages(['plan' => '먼저 글 계획을 만들어 주세요.']);
        }

        $factKeys = $post->facts()->pluck('fact_key')->all();
        $imageIds = $post->images()->pluck('id')->all();

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'outline' => ['required', 'array', 'min:1', 'max:15'],
            'outline.*.heading' => ['required', 'string', 'max:100'],
            'outline.*.purpose' => ['present', 'nullable', 'string', 'max:500'],
            'outline.*.key_points' => ['present', 'array', 'max:8'],
            'outline.*.key_points.*' => ['string', 'max:300'],
            'outline.*.fact_keys' => ['present', 'array'],
            'outline.*.fact_keys.*' => ['string', Rule::in($factKeys)],
            'outline.*.image_ids' => ['present', 'array'],
            'outline.*.image_ids.*' => ['integer', Rule::in($imageIds)],
        ], [
            'outline.*.fact_keys.*.in' => '지금 글에 없는 사실 항목입니다: :input',
            'outline.*.image_ids.*.in' => '이 글의 사진이 아닙니다.',
        ]);

        $placed = collect($data['outline'])->flatMap(fn ($s) => $s['image_ids']);
        if ($placed->count() !== $placed->unique()->count()) {
            throw ValidationException::withMessages(['outline' => '같은 사진을 두 섹션에 넣을 수 없습니다.']);
        }

        $plan = PlanIntegrity::refresh(array_merge($post->plan_json, ['outline' => $data['outline']]), $post);
        $post->forceFill(['plan_json' => $plan])->save();
        if (array_key_exists('title', $data)) {
            $post->update(['title' => $data['title']]);
        }

        return new PostResource($post->refresh()->load(['facts', 'images']));
    }
}
