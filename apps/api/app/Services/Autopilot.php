<?php

namespace App\Services;

use App\Enums\PostStatus;
use App\Jobs\AnalyzeImagesJob;
use App\Jobs\AnalyzeKeywordJob;
use App\Jobs\GenerateDraftJob;
use App\Jobs\GeneratePlanJob;
use App\Jobs\StartTwinJob;
use App\Models\Post;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

/** '글 만들기': 사진 분석 → 키워드 분석 → 계획 → (초안)을 필요한 것만 이어서 돌린다. 짝 글이 있으면 사진 분석 뒤 짝 글도 쓴다 */
class Autopilot
{
    /** @return bool 새로 시작했으면 true(이미 돌고 있으면 false) */
    public function start(Post $post, bool $untilPlan): bool
    {
        $post->load(['project.latestAnalysis', 'facts', 'images']);
        if ($post->pipeline_status === 'running') {
            return false;
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
        // 짝 글은 사진 분석이 끝난 뒤 사실·사진(분석 결과 포함)을 가져가 따로 쓴다(사진을 두 번 분석하지 않는다)
        if (! $post->twin_of_post_id && $post->twin()->exists()) {
            $jobs[] = new StartTwinJob($post);
        }

        // 키워드 분석은 없거나, 참고자료가 바뀌었거나, 기한이 지났거나, AI 해석이 빠졌을 때만 다시 한다
        $analysis = $post->project->latestAnalysis;
        $fresh = $analysis && $analysis->insight_json && $analysis->expires_at?->isFuture()
            && $analysis->source_hash === AnalyzeKeywordJob::sourceHash($post->project);
        if (! $fresh) {
            $post->project->markAnalysisQueued();
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

        return true;
    }
}
