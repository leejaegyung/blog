<?php

namespace App\Http\Controllers\Api;

use App\Enums\PostStatus;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Jobs\AnalyzeImagesJob;
use App\Jobs\AnalyzeKeywordJob;
use App\Jobs\GenerateDraftJob;
use App\Jobs\GeneratePlanJob;
use App\Models\KeywordProject;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** 단계별 마법사: 키워드로 글을 시작하고, '글 만들기' 한 번으로 나머지 작업을 이어서 돌린다. */
class WizardController extends Controller
{
    /** 키워드 프로젝트는 같은 키워드가 있으면 재사용하고 없으면 만든다. */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:50'],
            // 관리 › 카테고리별 학습에서 만든 카테고리. 고르면 그 카테고리의 참고 글이 키워드 분석에 함께 쓰인다
            'learning_category_id' => ['nullable', 'integer'],
        ]);
        $learning = null;
        if (! empty($data['learning_category_id'])) {
            $learning = $request->user()->keywordProjects()
                ->where('kind', KeywordProject::KIND_CATEGORY)
                ->find($data['learning_category_id']);
            if (! $learning) {
                throw ValidationException::withMessages(['learning_category_id' => '학습 카테고리를 찾을 수 없어요.']);
            }
        }
        $keyword = preg_replace('/\s+/u', ' ', trim($data['keyword']));
        if ($keyword === '') {
            throw ValidationException::withMessages(['keyword' => '키워드를 입력해 주세요.']);
        }

        $category = $learning?->keyword ?? ($data['category'] ?? null);
        $project = $request->user()->keywordProjects()->firstOrCreate(
            ['kind' => KeywordProject::KIND_KEYWORD, 'keyword' => $keyword],
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
            ->whereNull('plan_json')
            ->whereNull('content_json')
            ->doesntHave('images')
            ->doesntHave('facts')
            ->latest('id')
            ->first();
        $post = $empty ?? $request->user()->posts()->create(['keyword_project_id' => $project->id, 'tone' => 'natural', 'target_length' => 2500]);

        return (new PostResource($post->refresh()->load(['project.latestAnalysis', 'project.learningCategory:id,keyword', 'facts', 'images'])))->response()->setStatusCode($empty ? 200 : 201);
    }

    /** until=plan: 사진 분석→키워드 분석→계획까지만(글 계획 단계에서 사용자가 고른 뒤 초안을 쓴다). 기본은 초안까지. */
    public function autopilot(Request $request, Post $post): JsonResponse
    {
        $untilPlan = $request->input('until') === 'plan';
        Gate::authorize('update', $post);
        $post->load(['project.latestAnalysis', 'project.learningCategory:id,keyword', 'facts', 'images']);
        $respond = fn () => (new PostResource($post->refresh()->load(['project.latestAnalysis', 'project.learningCategory:id,keyword', 'facts', 'images'])))->response()->setStatusCode(202);

        if ($post->pipeline_status === 'running') {
            return $respond();
        }
        if (! $post->project) {
            throw ValidationException::withMessages(['post' => '키워드가 없는 글입니다.']);
        }
        if ($post->facts->isEmpty()) {
            throw ValidationException::withMessages(['facts' => '알려주고 싶은 내용을 1개 이상 입력해 주세요.']);
        }

        $jobs = [];
        // 사진 단계에서 시작한 분석이 아직 끝나지 않았어도 계획 전에 결과가 있도록 흐름에 넣는다(끝난 사진은 작업이 건너뛴다)
        $unanalyzed = $post->images->filter(fn ($image) => ! $image->vision_json)->modelKeys();
        if ($unanalyzed) {
            $post->images()->whereKey($unanalyzed)->update(['vision_status' => 'pending', 'vision_error' => null]);
            $jobs[] = (new AnalyzeImagesJob($post, $unanalyzed))->inPipeline($post->id);
        }

        // 키워드 분석은 없거나, 참고자료가 바뀌었거나, 기한이 지났거나, AI 해석이 빠졌을 때만 다시 한다
        $analysis = $post->project->latestAnalysis;
        $fresh = $analysis && $analysis->insight_json && $analysis->expires_at?->isFuture()
            && $analysis->source_hash === AnalyzeKeywordJob::sourceHash($post->project);
        if (! $fresh) {
            $post->project->forceFill(['status' => ProjectStatus::Analyzing])->save();
            $jobs[] = (new AnalyzeKeywordJob($post->project))->inPipeline($post->id);
        }

        $jobs[] = (new GeneratePlanJob($post, finishPipeline: $untilPlan))->inPipeline($post->id);
        if (! $untilPlan) {
            $jobs[] = (new GenerateDraftJob($post))->inPipeline($post->id);
        }

        $post->forceFill([
            'status' => PostStatus::Planning,
            'pipeline_status' => 'running',
            'pipeline_step' => $unanalyzed ? 'vision' : (! $fresh ? 'analysis' : 'plan'),
            'pipeline_error' => null,
            'plan_error' => null,
            'draft_error' => null,
        ])->save();
        Bus::chain($jobs)->dispatch();

        return $respond();
    }
}
