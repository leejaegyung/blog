<?php

namespace App\Jobs;

use App\Enums\ParseStatus;
use App\Enums\ProjectStatus;
use App\Models\KeywordAnalysis;
use App\Models\KeywordProject;
use App\Models\ReferenceDocument;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\GenerationRecorder;
use App\Jobs\Concerns\TracksPipeline;
use App\Jobs\Middleware\NoRetryAfterLlmStop;
use App\Services\AiWorker\LlmStopException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalyzeKeywordJob implements ShouldQueue
{
    use Queueable, TracksPipeline;

    // AI 호출(최대 320초)보다 길게. 짧으면 작업이 끊기고 다시 실행돼 사용량을 두 번 쓴다
    public int $timeout = 330;

    /** 분석 결과 유효기간(기획서 21장: 일반 키워드 7일) */
    public const TTL_DAYS = 7;

    public const FEATURES_VERSION = 'features-1';

    public int $tries = 3;


    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public KeywordProject $project) {}

    /** 참고자료 구성과 키워드가 같으면 같은 분석으로 본다(캐시 키). */
    /** @return list<int> 이 분석에 참고 글을 대는 프로젝트(자기 자신 + 고른 학습 카테고리) */
    public static function sourceProjectIds(KeywordProject $project): array
    {
        return array_values(array_filter([$project->id, $project->isCategory() ? null : $project->learning_category_id]));
    }

    public static function sourceHash(KeywordProject $project): string
    {
        $hashes = ReferenceDocument::whereIn('keyword_project_id', self::sourceProjectIds($project))
            ->where('parse_status', ParseStatus::Parsed)
            ->orderBy('content_hash')
            ->pluck('content_hash')
            ->all();

        return hash('sha256', implode('|', [$project->keyword, $project->category, $project->platform, ...$hashes]));
    }

    /** 시간 초과·시간당 한도로 멈춘 AI 호출은 다시 시도하지 않는다(토큰 누수 방지) */
    public function middleware(): array
    {
        return [new NoRetryAfterLlmStop];
    }

    public function handle(AiWorkerClient $worker, GenerationRecorder $recorder): void
    {
        $this->pipelineStep('analysis');
        $project = $this->project;
        // 진행 표시: 대기열에서 꺼내 AI로 정리하기 시작했다
        $project->forceFill(['analysis_step' => 'ai', 'analysis_started_at' => $project->analysis_started_at ?? now()])->save();

        // 키워드가 고른 학습 카테고리의 참고 글도 함께 쓴다(카테고리별 학습)
        $features = ReferenceDocument::whereIn('keyword_project_id', self::sourceProjectIds($project))
            ->where('parse_status', ParseStatus::Parsed)
            ->with('features')
            ->get()
            ->map(fn ($reference) => $reference->features)
            ->filter(fn ($features) => $features?->analyzer_version === self::FEATURES_VERSION)
            ->map(fn ($features) => collect($features->features_json)->except('extractor')->all())
            ->values()
            ->all();

        $result = $worker->analyzeKeyword([
            'keyword' => $project->keyword,
            'category' => $project->category,
            'platform' => $project->platform,
            'features' => $features,
        ]);

        $recorder->record($result['generations'], purpose: 'keyword_analysis',
            promptVersion: $result['prompt_version'], project: $project);

        $insight = $result['insight'];
        $stats = $result['stats'];

        $analysis = $project->analyses()->create([
            'primary_intent' => $insight['primary_intent'] ?? null,
            'related_keywords_json' => $insight['related_keywords'] ?? null,
            'common_topics_json' => $stats['topics'] ?? null,
            'recommended_outline_json' => $insight['recommended_outline'] ?? null,
            'title_patterns_json' => $insight['title_guidelines'] ?? null,
            'must_answer_json' => $insight['must_answer'] ?? null,
            'stats_json' => $stats,
            'insight_json' => $insight,
            'guide_json' => $result['guide'] ?? null,
            'insight_error' => $result['insight_error'],
            'prompt_version' => $result['prompt_version'],
            'source_hash' => self::sourceHash($project),
            'analyzer_version' => ($stats['version'] ?? 'stats-none').'+'.$result['prompt_version'],
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        $project->forceFill([
            'analysis_step' => null,
            'status' => ProjectStatus::Analyzed,
            'last_analyzed_at' => $analysis->created_at,
            'analysis_version' => $analysis->analyzer_version,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $this->pipelineFail($exception instanceof LlmStopException ? $exception->getMessage() : '키워드 분석 서비스에 연결하지 못했습니다.');
        $this->project->forceFill(['status' => ProjectStatus::Failed, 'analysis_step' => null])->save();
    }
}
