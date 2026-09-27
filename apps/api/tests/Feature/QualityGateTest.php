<?php

namespace Tests\Feature;

use App\Jobs\GenerateDraftJob;
use App\Models\Post;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\GenerationRecorder;
use App\Services\QualityGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QualityGateTest extends TestCase
{
    use RefreshDatabase;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->post = Post::factory()->create(['title' => '인계동 파스타 후기', 'target_length' => 1500]);
        $this->post->project->update(['keyword' => '인계동 파스타']);
        $this->post->project->analyses()->create(['analyzer_version' => 'x', 'stats_json' => ['keyword_per_1000_chars' => ['p75' => 3.5]]]);
        $this->post->facts()->create(['fact_key' => '가격', 'fact_value' => '19,000원']);
        $this->image = $this->post->images()->create(['storage_key' => 'a.jpg', 'vision_json' => ['usable' => false, 'privacy_flags' => ['사람 얼굴']]]);
        $this->post->forceFill([
            'plan_json' => ['forbidden_claims' => ['주차 단정 금지']],
            'content_json' => ['blocks' => [['type' => 'paragraph', 'text' => '런치 19,000원']], 'tags' => ['파스타']],
        ])->save();
    }

    private \App\Models\PostImage $image;

    private function report(float $score = 72.5): array
    {
        return [
            'version' => 'quality-1', 'content_hash' => 'x', 'score' => $score,
            'parts' => [['key' => 'facts', 'label' => '사용자 사실 반영', 'score' => 20, 'max' => 20]],
            'issues' => [['code' => 'privacy_photo', 'severity' => 'error', 'message' => '개인정보', 'block_index' => 1, 'excerpt' => null]],
            'metrics' => ['char_count' => 10],
        ];
    }

    public function test_quality_check_sends_post_state_and_stores_report(): void
    {
        Http::fake(['*/posts/quality-check' => Http::response($this->report())]);

        $this->actingAs($this->post->user)->postJson("/api/posts/{$this->post->id}/quality-check")
            ->assertOk()
            ->assertJsonPath('data.quality.score', 72.5)
            ->assertJsonPath('data.quality.issues.0.code', 'privacy_photo')
            ->assertJsonPath('data.quality_stale', false)
            ->assertJsonMissingPath('data.quality.source_hash');

        Http::assertSent(fn ($r) => $r['keyword'] === '인계동 파스타' && $r['title'] === '인계동 파스타 후기'
            && $r['blocks'][0]['text'] === '런치 19,000원' && $r['facts'][0]['fact_value'] === '19,000원'
            && $r['images'][0] === ['id' => $this->image->id, 'usable' => false, 'privacy_flags' => ['사람 얼굴']]
            && $r['keyword_density_p75'] === 3.5 && $r['target_length'] === 1500
            && $r['plan']['forbidden_claims'] === ['주차 단정 금지']);
        $this->assertNotNull($this->post->fresh()->quality_checked_at);
    }

    public function test_report_becomes_stale_after_content_changes(): void
    {
        Http::fake(['*/posts/quality-check' => Http::response($this->report())]);
        $this->actingAs($this->post->user)->postJson("/api/posts/{$this->post->id}/quality-check");

        $this->actingAs($this->post->user)->patchJson("/api/posts/{$this->post->id}", [
            'content' => ['blocks' => [['type' => 'paragraph', 'text' => '바뀐 본문']], 'tags' => []],
        ])->assertJsonPath('data.quality_stale', true);
    }

    public function test_requires_content_and_handles_worker_down(): void
    {
        $empty = Post::factory()->create();
        $this->actingAs($empty->user)->postJson("/api/posts/{$empty->id}/quality-check")->assertJsonValidationErrors('content');

        Http::fake(['*/posts/quality-check' => Http::response('boom', 500)]);
        $this->actingAs($this->post->user)->postJson("/api/posts/{$this->post->id}/quality-check")->assertStatus(503);

        $this->actingAs($empty->user)->postJson("/api/posts/{$this->post->id}/quality-check")->assertForbidden();
    }

    public function test_draft_generation_runs_quality_check_and_survives_its_failure(): void
    {
        $draft = ['draft' => ['title' => 't', 'blocks' => [['type' => 'paragraph', 'text' => '새 초안']], 'tags' => [], 'text' => 't',
            'char_count' => 3, 'target_length' => 1500, 'keyword_count' => 0, 'warnings' => []],
            'draft_error' => null, 'prompt_version' => 'blog-draft-v2', 'generations' => []];
        $job = fn () => (new GenerateDraftJob($this->post->fresh()))->handle(app(AiWorkerClient::class), app(GenerationRecorder::class), app(QualityGate::class));

        Http::fake(['*/posts/draft' => Http::response($draft), '*/posts/quality-check' => Http::response($this->report(88))]);
        $job();
        $this->assertSame(88, $this->post->fresh()->quality_json['score']);

        Http::fake(['*/posts/draft' => Http::response($draft), '*/posts/quality-check' => Http::response('boom', 500)]);
        $job();
        $this->assertSame('새 초안', $this->post->fresh()->content_json['blocks'][0]['text']);
    }
}
