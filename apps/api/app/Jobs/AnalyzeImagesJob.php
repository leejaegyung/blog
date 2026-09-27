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

    /**
     * @param  list<int>  $imageIds
     * @param  bool  $force  이미 분석된 사진도 다시 분석한다(사용자가 다시 분석을 요청한 경우)
     */
    public function __construct(public Post $post, public array $imageIds, public bool $force = false) {}

    public function handle(AiWorkerClient $worker, GenerationRecorder $recorder): void
    {
        $this->pipelineStep('vision');
        $post = $this->post->load('project', 'facts');
        // 사진 단계에서 먼저 시작한 분석과 글 계획 흐름의 분석이 겹칠 수 있어, 이미 끝난 사진은 건너뛴다
        $images = $post->images()->whereKey($this->imageIds)
            ->when(! $this->force, fn ($q) => $q->whereNull('vision_json'))
            ->get();
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
