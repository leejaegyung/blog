<?php

namespace App\Services;

use App\Models\Post;
use App\Services\AiWorker\AiWorkerClient;

/** 글의 현재 본문으로 품질 검사를 돌리고 결과를 저장한다. 검사는 코드 규칙이라 동기로 처리한다. */
class QualityGate
{
    public function __construct(private AiWorkerClient $worker) {}

    public function run(Post $post): Post
    {
        $post->loadMissing(['project.latestAnalysis', 'facts', 'images']);

        $report = $this->worker->qualityCheck([
            'keyword' => $post->project?->keyword ?? '',
            'title' => $post->title ?? '',
            'blocks' => $post->content_json['blocks'] ?? [],
            'tags' => $post->content_json['tags'] ?? [],
            'facts' => $post->facts->map->only(['fact_key', 'fact_value'])->values()->all(),
            'plan' => $post->plan_json,
            'images' => $post->images->map(fn ($image) => [
                'id' => $image->id,
                'usable' => $image->vision_json['usable'] ?? true,
                'privacy_flags' => $image->vision_json['privacy_flags'] ?? [],
            ])->values()->all(),
            'target_length' => $post->target_length ?? 2500,
            'keyword_density_p75' => $post->project?->latestAnalysis?->stats_json['keyword_per_1000_chars']['p75'] ?? null,
        ]);

        $post->forceFill([
            'quality_json' => $report + ['source_hash' => self::sourceHash($post)],
            'quality_checked_at' => now(),
        ])->save();

        return $post;
    }

    /** 검사 뒤 제목·본문·사실·사진 분석이 바뀌었는지 알기 위한 지문 */
    public static function sourceHash(Post $post): string
    {
        return md5(json_encode([
            $post->title,
            $post->content_json,
            $post->facts->map->only(['fact_key', 'fact_value'])->values(),
            $post->images->map->only(['id', 'vision_json'])->values(),
        ]));
    }
}
