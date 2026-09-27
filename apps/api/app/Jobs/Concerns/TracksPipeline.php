<?php

namespace App\Jobs\Concerns;

use App\Models\Post;

/**
 * '글 만들기' 체인 안에서 돌 때 글의 진행 단계를 기록한다. 체인 밖(개별 버튼)에서는 아무것도 하지 않는다.
 */
trait TracksPipeline
{
    public ?int $pipelinePostId = null;

    public function inPipeline(int $postId): static
    {
        $this->pipelinePostId = $postId;

        return $this;
    }

    protected function pipelineStep(string $step): void
    {
        $this->pipelinePost()?->forceFill(['pipeline_step' => $step])->save();
    }

    protected function pipelineFail(?string $message): void
    {
        $this->pipelinePost()?->forceFill([
            'pipeline_status' => 'failed',
            'pipeline_error' => $message ?? '작업 중 오류가 발생했습니다.',
        ])->save();
    }

    protected function pipelineDone(): void
    {
        $this->pipelinePost()?->forceFill(['pipeline_status' => 'done', 'pipeline_step' => null, 'pipeline_error' => null])->save();
    }

    protected function pipelineFailed(): bool
    {
        return $this->pipelinePost()?->pipeline_status === 'failed';
    }

    private function pipelinePost(): ?Post
    {
        return $this->pipelinePostId ? Post::find($this->pipelinePostId) : null;
    }
}
