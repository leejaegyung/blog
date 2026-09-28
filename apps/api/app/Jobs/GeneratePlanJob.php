<?php

namespace App\Jobs;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\GenerationRecorder;
use App\Jobs\Concerns\TracksPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GeneratePlanJob implements ShouldQueue
{
    use Queueable, TracksPipeline;

    // 기다리는 동안 글이 지워졌으면 조용히 버린다
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public int $timeout = 280;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** @param  bool  $finishPipeline  계획이 체인의 마지막 단계면 성공 시 진행 상태를 끝낸다 */
    public function __construct(public Post $post, public bool $finishPipeline = false) {}

    public function handle(AiWorkerClient $worker, GenerationRecorder $recorder): void
    {
        $this->pipelineStep('plan');
        $post = $this->post->fresh()->load(['project.latestAnalysis', 'facts', 'images']);
        if ($this->pipelinePostId) {
            $post->forceFill(['status' => PostStatus::Planning])->save();
        }
        $analysis = $post->project?->latestAnalysis;

        $result = $worker->planPost([
            'keyword' => $post->project?->keyword ?? '',
            'category' => $post->project?->category,
            'tone' => $post->tone?->value ?? 'natural',
            'target_length' => $post->target_length ?? 2500,
            'facts' => $post->facts->map->only(['fact_key', 'fact_value'])->values()->all(),
            'images' => $post->images->map(fn ($image) => [
                'id' => $image->id,
                'sort_order' => $image->sort_order,
                'taken_at' => $image->taken_at?->toIso8601String(),
                'vision' => $image->vision_json
                    ? collect($image->vision_json)->only(['type', 'description', 'usable', 'suggested_section'])->all()
                    : null,
            ])->values()->all(),
            'analysis' => $analysis ? ['stats' => $analysis->stats_json, 'insight' => $analysis->insight_json] : null,
        ]);

        $recorder->record($result['generations'], purpose: 'plan', promptVersion: $result['prompt_version'], post: $post);

        if ($result['plan'] === null) {
            $post->forceFill(['status' => PostStatus::Failed, 'plan_error' => $result['plan_error']])->save();
            $this->pipelineFail($result['plan_error']);

            return;
        }

        $post->forceFill([
            'plan_json' => $result['plan'],
            'plan_error' => null,
            'status' => PostStatus::Planned,
            'title' => $post->title ?: ($result['plan']['title_candidates'][0] ?? null),
        ])->save();

        if ($this->finishPipeline) {
            $this->pipelineDone();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->pipelineFail('글 계획 서비스에 연결하지 못했습니다.');
        $this->post->forceFill([
            'status' => PostStatus::Failed,
            'plan_error' => '글 계획 서비스에 연결하지 못했습니다. 잠시 뒤 다시 시도해 주세요.',
        ])->save();
    }
}
