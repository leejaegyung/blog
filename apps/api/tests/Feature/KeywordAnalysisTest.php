<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Jobs\AnalyzeKeywordJob;
use App\Models\Generation;
use App\Models\KeywordProject;
use App\Models\ReferenceDocument;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\GenerationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KeywordAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private KeywordProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = KeywordProject::factory()->create(['keyword' => '인계동 파스타', 'category' => '맛집']);
    }

    private function reference(string $hash, string $status = 'parsed', string $version = 'features-1'): ReferenceDocument
    {
        $reference = $this->project->references()->create([
            'source_type' => 'user_url', 'source_url' => "https://ex.com/{$hash}", 'usage_permission' => 'user_provided',
            'parse_status' => $status, 'content_hash' => $hash,
        ]);
        $reference->features()->create([
            'char_count' => 1000, 'analyzer_version' => $version,
            'features_json' => ['version' => $version, 'layout' => 'IPHP', 'extractor' => 'text'],
        ]);

        return $reference;
    }

    private function workerResponse(bool $withInsight = true): array
    {
        return [
            'stats' => ['version' => 'stats-1', 'reference_count' => 2, 'topics' => [['term' => '주차', 'documents' => 2, 'share' => 1.0]]],
            'insight' => $withInsight ? [
                'primary_intent' => '맛집 방문 후기',
                'intent_distribution' => [['label' => '맛집 방문 후기', 'share' => 0.7]],
                'must_answer' => ['주차가 되나요?'],
                'recommended_outline' => [['heading' => '위치와 주차', 'purpose' => '찾아가는 법', 'photo_hint' => '외관 1장']],
                'title_guidelines' => ['키워드를 앞에'],
                'related_keywords' => ['주차'],
                'writing_tips' => ['사진은 2~3장씩'],
            ] : null,
            'insight_error' => $withInsight ? null : 'AI 해석을 만들지 못했습니다: not_configured',
            'guide' => [
                'version' => 'guide-1', 'reference_count' => 2,
                'targets' => [['key' => 'length', 'label' => '본문 길이', 'target' => '1,800~2,600자', 'basis' => '참고 글 2개', 'min' => 1800, 'max' => 2600]],
                'principles' => ['직접 찍은 사진'],
                'hashtags' => [['tag' => '인계동파스타', 'source' => 'keyword', 'share' => null], ['tag' => '인계동맛집', 'source' => 'references', 'share' => 1.0]],
                'checks' => ['title_keyword_start' => true, 'keyword_in_first_paragraph' => true, 'photo_min' => 4, 'heading_min' => 3, 'hashtag_min' => 5, 'hashtag_max' => 15],
            ],
            'prompt_version' => 'keyword-analysis-v1',
            'generations' => [[
                'provider' => 'anthropic', 'model' => 'claude-opus-5', 'status' => $withInsight ? 'success' : 'failed',
                'input_tokens' => 800, 'output_tokens' => 300, 'latency_ms' => 9000,
                'error_kind' => $withInsight ? null : 'not_configured',
            ]],
        ];
    }

    private function runJob(): void
    {
        (new AnalyzeKeywordJob($this->project))->handle(app(AiWorkerClient::class), app(GenerationRecorder::class));
    }

    public function test_job_sends_only_current_parsed_features_and_saves_analysis(): void
    {
        $this->reference('a');
        $this->reference('b');
        $this->reference('c', status: 'duplicate');
        $this->reference('d', version: 'parse-1');
        Http::fake(['*/keywords/analyze' => Http::response($this->workerResponse())]);

        $this->runJob();

        Http::assertSent(function ($request) {
            return $request['keyword'] === '인계동 파스타' && $request['category'] === '맛집'
                && count($request['features']) === 2
                && ! array_key_exists('extractor', $request['features'][0]);
        });
        $analysis = $this->project->latestAnalysis()->sole();
        $this->assertSame('맛집 방문 후기', $analysis->primary_intent);
        $this->assertSame(['주차가 되나요?'], $analysis->must_answer_json);
        $this->assertSame('1,800~2,600자', $analysis->guide_json['targets'][0]['target']);
        // 사용자가 고친 적이 없으면 분석의 추천 해시태그를 쓴다
        $this->assertSame(['인계동파스타', '인계동맛집'], $this->project->fresh()->hashtags());
        $this->assertSame('stats-1+keyword-analysis-v1', $analysis->analyzer_version);
        $this->assertTrue($analysis->expires_at->between(now()->addDays(6), now()->addDays(8)));
        $this->assertSame(ProjectStatus::Analyzed, $this->project->fresh()->status);
        $this->assertSame(['keyword_analysis', 'keyword-analysis-v1', $this->project->id], [
            Generation::sole()->purpose, Generation::sole()->prompt_version, Generation::sole()->keyword_project_id,
        ]);
    }

    public function test_stats_are_saved_even_when_llm_fails(): void
    {
        $this->reference('a');
        Http::fake(['*/keywords/analyze' => Http::response($this->workerResponse(withInsight: false))]);

        $this->runJob();

        $analysis = $this->project->latestAnalysis()->sole();
        $this->assertNull($analysis->insight_json);
        $this->assertSame(2, $analysis->stats_json['reference_count']);
        $this->assertStringContainsString('not_configured', $analysis->insight_error);
        $this->assertSame(ProjectStatus::Analyzed, $this->project->fresh()->status);
    }

    public function test_failed_job_marks_project_failed(): void
    {
        (new AnalyzeKeywordJob($this->project))->failed(null);

        $this->assertSame(ProjectStatus::Failed, $this->project->fresh()->status);
    }

    public function test_analyze_endpoint_queues_job(): void
    {
        Queue::fake();

        $this->actingAs($this->project->user)->postJson("/api/projects/{$this->project->id}/analyze")
            ->assertStatus(202)
            ->assertJsonPath('status', 'analyzing')
            ->assertJsonPath('cached', false);

        Queue::assertPushed(AnalyzeKeywordJob::class);

        // 분석 중에 다시 눌러도 중복으로 쌓지 않는다
        $this->actingAs($this->project->user)->postJson("/api/projects/{$this->project->id}/analyze")->assertStatus(202);
        Queue::assertPushed(AnalyzeKeywordJob::class, 1);
    }

    public function test_reuses_fresh_analysis_until_references_change_or_forced(): void
    {
        Queue::fake();
        $this->reference('a');
        Http::fake(['*/keywords/analyze' => Http::response($this->workerResponse())]);
        $this->runJob();
        $url = "/api/projects/{$this->project->id}/analyze";
        $user = $this->project->user;

        $this->actingAs($user)->postJson($url)->assertOk()->assertJsonPath('cached', true);
        Queue::assertNothingPushed();

        $this->actingAs($user)->postJson($url, ['force' => true])->assertStatus(202);
        Queue::assertPushed(AnalyzeKeywordJob::class, 1);

        $this->project->refresh()->forceFill(['status' => ProjectStatus::Analyzed])->save();
        $this->reference('b');
        $this->actingAs($user)->postJson($url)->assertStatus(202);
        Queue::assertPushed(AnalyzeKeywordJob::class, 2);
    }

    public function test_analysis_without_insight_is_not_reused(): void
    {
        Queue::fake();
        Http::fake(['*/keywords/analyze' => Http::response($this->workerResponse(withInsight: false))]);
        $this->runJob();

        $this->actingAs($this->project->user)->postJson("/api/projects/{$this->project->id}/analyze")->assertStatus(202);
    }

    public function test_show_marks_stale_analysis(): void
    {
        $this->reference('a');
        Http::fake(['*/keywords/analyze' => Http::response($this->workerResponse())]);
        $this->runJob();
        $url = "/api/projects/{$this->project->id}/analysis";

        $this->actingAs($this->project->user)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.primary_intent', '맛집 방문 후기')
            ->assertJsonPath('data.writing_tips.0', '사진은 2~3장씩')
            ->assertJsonPath('data.stats.reference_count', 2)
            ->assertJsonPath('data.stale', false);

        $this->reference('b');
        $this->actingAs($this->project->user)->getJson($url)->assertJsonPath('data.stale', true);
    }

    public function test_show_without_analysis_and_authorization(): void
    {
        $url = "/api/projects/{$this->project->id}/analysis";

        $this->actingAs($this->project->user)->getJson($url)->assertOk()->assertJsonPath('data', null);
        $this->actingAs(KeywordProject::factory()->create()->user)->getJson($url)->assertForbidden();
        $this->actingAs(KeywordProject::factory()->create()->user)
            ->postJson("/api/projects/{$this->project->id}/analyze")->assertForbidden();
    }
}
