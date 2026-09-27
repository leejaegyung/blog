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
        ]);
        $keyword = preg_replace('/\s+/u', ' ', trim($data['keyword']));
        if ($keyword === '') {
            throw ValidationException::withMessages(['keyword' => '키워드를 입력해 주세요.']);
        }

        $project = $request->user()->keywordProjects()->firstOrCreate(['keyword' => $keyword], ['category' => $data['category'] ?? null]);
        if (! $project->category && ! empty($data['category'])) {
            $project->update(['category' => $data['category']]);
        }
        $post = $request->user()->posts()->create(['keyword_project_id' => $project->id, 'tone' => 'natural', 'target_length' => 2500]);

        return (new PostResource($post->refresh()->load(['project', 'facts', 'images'])))->response()->setStatusCode(201);
    }

    /** until=plan: 사진 분석→키워드 분석→계획까지만(글 계획 단계에서 사용자가 고른 뒤 초안을 쓴다). 기본은 초안까지. */
    public function autopilot(Request $request, Post $post): JsonResponse
    {
        $untilPlan = $request->input('until') === 'plan';
        Gate::authorize('update', $post);
        $post->load(['project.latestAnalysis', 'facts', 'images']);
        $respond = fn () => (new PostResource($post->refresh()->load(['project', 'facts', 'images'])))->response()->setStatusCode(202);

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
        $unanalyzed = $post->images->filter(fn ($image) => ! $image->vision_json && $image->vision_status !== 'pending')->modelKeys();
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
