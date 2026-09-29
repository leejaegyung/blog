<?php

namespace App\Jobs;

use App\Models\Post;
use App\Services\Autopilot;
use App\Services\TwinPosts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 원래 글의 '글 만들기' 흐름 안에서 짝 글을 맞추고 초안까지 쓰게 한다.
 * 짝 글에 문제가 있어도 원래 글 흐름은 멈추지 않는다.
 */
class StartTwinJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** force: 짝 글을 고쳤어도 다시 쓴다(6단계 "다시 쓰기") */
    public function __construct(public Post $primary, public bool $force = false) {}

    public function handle(TwinPosts $twins, Autopilot $autopilot): void
    {
        $twin = $this->primary->twin()->first();
        if (! $twin || $twin->pipeline_status === 'running') {
            return;
        }
        try {
            $changed = $twins->sync($this->primary, $twin);
            // 사용자가 짝 글을 고쳤거나 이미 올렸으면 저절로 덮어쓰지 않는다
            $untouched = $twin->content_json === null
                || ($twin->content_json == $twin->content_original_json && ! $twin->tistory_url && ! $twin->published_url);
            if ($this->force || $twin->content_json === null || ($changed && $untouched)) {
                $autopilot->start($twin->refresh(), untilPlan: false);
            }
        } catch (Throwable $e) {
            Log::warning('짝 글 시작 실패', ['post_id' => $this->primary->id, 'twin_id' => $twin->id, 'error' => class_basename($e)]);
            $twin->forceFill(['pipeline_status' => 'failed', 'pipeline_error' => '짝 글을 쓰지 못했어요. 6단계에서 다시 쓰기를 눌러 주세요.'])->save();
        }
    }
}
