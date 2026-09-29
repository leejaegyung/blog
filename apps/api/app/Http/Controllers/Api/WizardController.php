<?php

namespace App\Http\Controllers\Api;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\KeywordProject;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Platform;
use App\Services\Autopilot;
use App\Services\TwinPosts;

/** 단계별 마법사: 키워드로 글을 시작하고, '글 만들기' 한 번으로 나머지 작업을 이어서 돌린다. */
class WizardController extends Controller
{
    public const BOTH = 'both';

    /** 키워드 프로젝트는 같은 키워드가 있으면 재사용하고 없으면 만든다. */
    public function start(Request $request, TwinPosts $twins): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:50'],
            // 관리 › 카테고리별 학습에서 만든 카테고리. 고르면 그 카테고리의 참고 글이 키워드 분석에 함께 쓰인다
            'learning_category_id' => ['nullable', 'integer'],
            // 올릴 곳. 같은 키워드라도 네이버용·티스토리용 분석이 따로 있다
            // both: 네이버·티스토리에 동시에 올린다(네이버 글 + 티스토리 짝 글을 따로 쓴다)
            'platform' => ['sometimes', Rule::in([...Platform::ALL, self::BOTH])],
            'twin_learning_category_id' => ['nullable', 'integer'],
        ]);
        $both = ($data['platform'] ?? null) === self::BOTH;
        $platform = $both ? Platform::NAVER : ($data['platform'] ?? Platform::NAVER);
        $twinLearning = null;
        if ($both && ! empty($data['twin_learning_category_id'])) {
            $twinLearning = $request->user()->keywordProjects()
                ->where('kind', KeywordProject::KIND_CATEGORY)
                ->where('platform', TwinPosts::otherPlatform($platform))
                ->find($data['twin_learning_category_id']);
            if (! $twinLearning) {
                throw ValidationException::withMessages(['twin_learning_category_id' => '티스토리용 학습 카테고리를 찾을 수 없어요.']);
            }
        }
        $learning = null;
        if (! empty($data['learning_category_id'])) {
            $learning = $request->user()->keywordProjects()
                ->where('kind', KeywordProject::KIND_CATEGORY)
                ->where('platform', $platform)
                ->find($data['learning_category_id']);
            if (! $learning) {
                throw ValidationException::withMessages(['learning_category_id' => '학습 카테고리를 찾을 수 없어요('.Platform::label($platform).'용 카테고리만 고를 수 있어요).']);
            }
        }
        $keyword = preg_replace('/\s+/u', ' ', trim($data['keyword']));
        if ($keyword === '') {
            throw ValidationException::withMessages(['keyword' => '키워드를 입력해 주세요.']);
        }

        $category = $learning?->keyword ?? ($data['category'] ?? null);
        $project = $request->user()->keywordProjects()->firstOrCreate(
            ['kind' => KeywordProject::KIND_KEYWORD, 'platform' => $platform, 'keyword' => $keyword],
            ['category' => $category],
        );
        if ($learning) {
            // 이번에 고른 학습 카테고리로 바꾼다(키워드 분석이 그 카테고리의 참고 글을 쓴다)
            $project->update(['learning_category_id' => $learning->id, 'category' => $learning->keyword]);
        } elseif (! $project->category && $category) {
            $project->update(['category' => $category]);
        }
        // 같은 키워드로 시작만 하고 아무것도 넣지 않은 글이 있으면 새로 만들지 않고 그 글을 이어 쓴다
        $empty = $request->user()->posts()
            ->where('keyword_project_id', $project->id)
            ->where('status', PostStatus::Draft)
            // 짝 글은 원래 글이 채운다(따로 이어 쓰는 빈 글로 보지 않는다)
            ->whereNull('twin_of_post_id')
            ->whereNull('plan_json')
            ->whereNull('content_json')
            ->doesntHave('images')
            ->doesntHave('facts')
            ->latest('id')
            ->first();
        $post = $empty ?? $request->user()->posts()->create(['keyword_project_id' => $project->id, 'platform' => $platform, 'tone' => 'natural', 'target_length' => 2500]);
        if ($both) {
            $twins->ensure($post, $twinLearning);
        }

        return (new PostResource($post->refresh()->load(['project.latestAnalysis', 'project.learningCategory:id,keyword', 'facts', 'images'])))->response()->setStatusCode($empty ? 200 : 201);
    }

    /** until=plan: 사진 분석→키워드 분석→계획까지만(글 계획 단계에서 사용자가 고른 뒤 초안을 쓴다). 기본은 초안까지. */
    public function autopilot(Request $request, Post $post, Autopilot $autopilot): JsonResponse
    {
        Gate::authorize('update', $post);
        $autopilot->start($post, untilPlan: $request->input('until') === 'plan');

        return (new PostResource($post->refresh()->load(['project.latestAnalysis', 'project.learningCategory:id,keyword', 'facts', 'images'])))->response()->setStatusCode(202);
    }
}
