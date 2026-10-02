<?php

namespace App\Jobs;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\GenerationRecorder;
use App\Services\QualityGate;
use App\Jobs\Concerns\TracksPipeline;
use App\Jobs\Middleware\NoRetryAfterLlmStop;
use App\Services\AiWorker\LlmStopException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateDraftJob implements ShouldQueue
{
    use Queueable, TracksPipeline;

    // AI 호출(최대 320초)보다 길게. 짧으면 작업이 끊기고 다시 실행돼 사용량을 두 번 쓴다
    public int $timeout = 330;

    // 기다리는 동안 글이 지워졌으면 조용히 버린다
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;


    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public Post $post) {}

    /** 시간 초과·시간당 한도로 멈춘 AI 호출은 다시 시도하지 않는다(토큰 누수 방지) */
    public function middleware(): array
    {
        return [new NoRetryAfterLlmStop];
    }

    public function handle(AiWorkerClient $worker, GenerationRecorder $recorder, QualityGate $gate): void
    {
        $post = $this->post->fresh()->load(['project.latestAnalysis', 'facts', 'images']);
        // 체인에서 앞 단계(계획)가 실패했으면 초안을 쓰지 않는다
        if ($this->pipelinePostId && ($this->pipelineFailed() || ! $post->plan_json)) {
            return;
        }
        $this->pipelineStep('draft');
        if ($this->pipelinePostId) {
            $post->forceFill(['status' => PostStatus::Generating])->save();
        }
        $plan = $post->plan_json;

        $result = $worker->draftPost([
            'keyword' => $post->project?->keyword ?? '',
            'platform' => $post->platform,
            // 같은 경험을 다른 플랫폼에도 따로 올린다 → 서로 다르게 쓴다
            'twin' => $post->twin_of_post_id !== null || $post->twin()->exists(),
            'tone' => $post->tone?->value ?? 'natural',
            'target_length' => $post->target_length ?? 2500,
            'title' => $post->title ?: ($plan['title_candidates'][0] ?? ''),
            'facts' => $post->facts->map->only(['fact_key', 'fact_value'])->values()->all(),
            'plan' => $plan,
            'image_ids' => $post->images->pluck('id')->all(),
            'photo_notes' => $post->images->filter(fn ($image) => $image->vision_json)
                ->mapWithKeys(fn ($image) => [$image->id => collect($image->vision_json)->only(['type', 'description'])->all()])
                ->all() ?: (object) [],
            // 키워드의 해시태그를 초안 태그로 자동으로 단다
            'hashtags' => $post->project?->hashtags() ?? [],
            // 참고 글(학습 카테고리 포함)의 말투 분포: 어미·문장 길이·감탄·이모지 빈도에 맞춰 사람처럼 쓴다
            'voice' => $post->project?->latestAnalysis?->stats_json['voice'] ?? null,
        ]);

        $recorder->record($result['generations'], purpose: 'draft', promptVersion: $result['prompt_version'], post: $post);

        $draft = $result['draft'];
        if ($draft === null) {
            $post->forceFill(['status' => PostStatus::Failed, 'draft_error' => $result['draft_error']])->save();
            $this->pipelineFail($result['draft_error']);

            return;
        }

        $post->forceFill([
            'content_json' => ['blocks' => $draft['blocks'], 'tags' => $draft['tags']],
            'content_original_json' => ['blocks' => $draft['blocks'], 'tags' => $draft['tags']],
            'content_text' => $draft['text'],
            'draft_meta_json' => collect($draft)->only(['char_count', 'target_length', 'keyword_count', 'warnings'])->all(),
            'draft_error' => null,
            'generation_version' => $result['prompt_version'],
            'status' => PostStatus::Review,
        ])->save();

        // 초안이 나오면 바로 품질 검사를 돌린다. 실패해도 초안은 그대로 둔다(편집 화면에서 다시 검사 가능).
        $this->pipelineStep('quality');
        try {
            $gate->run($post->refresh());
        } catch (Throwable $e) {
            report($e);
        }
        $this->pipelineDone();
    }

    public function failed(?Throwable $exception): void
    {
        $this->pipelineFail($exception instanceof LlmStopException ? $exception->getMessage() : '초안 서비스에 연결하지 못했습니다.');
        $this->post->forceFill([
            'status' => PostStatus::Failed,
            'draft_error' => $exception instanceof LlmStopException ? $exception->getMessage() : '초안 서비스에 연결하지 못했습니다. 잠시 뒤 다시 시도해 주세요.',
        ])->save();
    }
}
