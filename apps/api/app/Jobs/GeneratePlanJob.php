<?php

namespace App\Jobs;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\GenerationRecorder;
use App\Jobs\Concerns\TracksPipeline;
use App\Jobs\Middleware\NoRetryAfterLlmStop;
use App\Services\AiWorker\LlmStopException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GeneratePlanJob implements ShouldQueue
{
    use Queueable, TracksPipeline;

    // AI 호출(최대 320초)보다 길게. 짧으면 작업이 끊기고 다시 실행돼 사용량을 두 번 쓴다
    public int $timeout = 330;

    // 기다리는 동안 글이 지워졌으면 조용히 버린다
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;


    /** @var list<int> */
    public array $backoff = [30, 120];

    /** @param  bool  $finishPipeline  계획이 체인의 마지막 단계면 성공 시 진행 상태를 끝낸다 */
    public function __construct(public Post $post, public bool $finishPipeline = false) {}

    /** 시간 초과·시간당 한도로 멈춘 AI 호출은 다시 시도하지 않는다(토큰 누수 방지) */
    public function middleware(): array
    {
        return [new NoRetryAfterLlmStop];
    }

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
            'platform' => $post->platform,
            // 같은 경험을 다른 플랫폼에도 따로 올린다 → 서로 다르게 쓴다
            'twin' => $post->twin_of_post_id !== null || $post->twin()->exists(),
            // 정보 전달 글인지 일상 기록인지(일상이면 하루 이야기처럼, 정보는 이야기 속에 지나가듯)
            'mode' => $post->writingMode(),
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
        $this->pipelineFail($exception instanceof LlmStopException ? $exception->getMessage() : '글 계획 서비스에 연결하지 못했습니다.');
        $this->post->forceFill([
            'status' => PostStatus::Failed,
            'plan_error' => $exception instanceof LlmStopException ? $exception->getMessage() : '글 계획 서비스에 연결하지 못했습니다. 잠시 뒤 다시 시도해 주세요.',
        ])->save();
    }
}
