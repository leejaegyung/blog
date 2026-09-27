<?php

namespace App\Jobs;

use App\Models\Post;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\GenerationRecorder;
use App\Jobs\Concerns\TracksPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalyzeImagesJob implements ShouldQueue
{
    use Queueable, TracksPipeline;

    public int $tries = 3;

    public int $timeout = 290;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** @param  list<int>  $imageIds */
    public function __construct(public Post $post, public array $imageIds) {}

    public function handle(AiWorkerClient $worker, GenerationRecorder $recorder): void
    {
        $this->pipelineStep('vision');
        $post = $this->post->load('project', 'facts');
        $images = $post->images()->whereKey($this->imageIds)->get();
        if ($images->isEmpty()) {
            return;
        }

        $result = $worker->analyzeImages([
            'keyword' => $post->project?->keyword ?? '',
            'category' => $post->project?->category,
            'facts' => $post->facts->map->only(['fact_key', 'fact_value'])->values()->all(),
            'images' => $images->map(fn ($image) => ['id' => $image->id, 'storage_key' => $image->storage_key])->values()->all(),
        ]);

        $recorder->record($result['generations'], purpose: 'vision', promptVersion: $result['prompt_version'], post: $post);

        $byId = $images->keyBy('id');
        foreach ($result['results'] as $vision) {
            $byId[$vision['id']]?->forceFill([
                'vision_json' => collect($vision)->except('id')->all(),
                'vision_status' => 'done',
                'vision_error' => null,
            ])->save();
        }
        foreach ($result['failed_ids'] as $id) {
            $byId[$id]?->forceFill(['vision_status' => 'failed', 'vision_error' => $result['error'] ?? '분석 결과가 없습니다.'])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->pipelineFail('사진 분석 서비스에 연결하지 못했습니다.');
        $this->post->images()->whereKey($this->imageIds)->where('vision_status', 'pending')->update([
            'vision_status' => 'failed',
            'vision_error' => '사진 분석 서비스에 연결하지 못했습니다.',
        ]);
    }
}
