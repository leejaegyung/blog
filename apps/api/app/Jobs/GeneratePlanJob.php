<?php

namespace App\Jobs;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\GenerationRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GeneratePlanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 280;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public Post $post) {}

    public function handle(AiWorkerClient $worker, GenerationRecorder $recorder): void
    {
        $post = $this->post->load(['project.latestAnalysis', 'facts', 'images']);
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

            return;
        }

        $post->forceFill([
            'plan_json' => $result['plan'],
            'plan_error' => null,
            'status' => PostStatus::Planned,
            'title' => $post->title ?: ($result['plan']['title_candidates'][0] ?? null),
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $this->post->forceFill([
            'status' => PostStatus::Failed,
            'plan_error' => '글 계획 서비스에 연결하지 못했습니다. 잠시 뒤 다시 시도해 주세요.',
        ])->save();
    }
}
