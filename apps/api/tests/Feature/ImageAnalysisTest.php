<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeImagesJob;
use App\Jobs\GenerateDraftJob;
use App\Jobs\GeneratePlanJob;
use App\Models\Generation;
use App\Models\Post;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\GenerationRecorder;
use App\Services\QualityGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImageAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->post = Post::factory()->create();
        $this->post->facts()->create(['fact_key' => '대표 메뉴', 'fact_value' => '봉골레']);
    }

    private function vision(int $id, string $type = 'food'): array
    {
        return ['id' => $id, 'type' => $type, 'description' => '오일 파스타로 보이는 음식', 'usable' => true,
            'quality_score' => 0.8, 'suggested_section' => '메인 메뉴', 'caption_hint' => '파스타', 'privacy_flags' => []];
    }

    public function test_endpoint_queues_only_unanalyzed_images(): void
    {
        Queue::fake();
        $done = $this->post->images()->create(['storage_key' => 'a.jpg', 'vision_json' => ['type' => 'food'], 'vision_status' => 'done']);
        $new = $this->post->images()->create(['storage_key' => 'b.jpg']);
        $url = "/api/posts/{$this->post->id}/images/analyze";

        $this->actingAs($this->post->user)->postJson($url)->assertStatus(202)->assertJsonPath('queued', 1);
        Queue::assertPushed(AnalyzeImagesJob::class, fn ($job) => $job->imageIds === [$new->id]);
        $this->assertSame('pending', $new->fresh()->vision_status);

        // 분석 중인 사진은 다시 넣지 않는다
        $this->actingAs($this->post->user)->postJson($url)->assertOk()->assertJsonPath('queued', 0);

        $this->actingAs($this->post->user)->postJson($url, ['force' => true])->assertJsonPath('queued', 1);
        Queue::assertPushed(AnalyzeImagesJob::class, fn ($job) => $job->imageIds === [$done->id] && $job->force);
    }

    public function test_job_skips_photos_already_analyzed_unless_forced(): void
    {
        $done = $this->post->images()->create(['storage_key' => 'users/1/a.jpg', 'vision_json' => ['type' => 'food'], 'vision_status' => 'done']);
        Http::fake(['*/images/analyze' => Http::response([
            'results' => [$this->vision($done->id)], 'failed_ids' => [], 'prompt_version' => 'photo-analysis-v1', 'generations' => [],
        ])]);

        (new AnalyzeImagesJob($this->post, [$done->id]))->handle(app(AiWorkerClient::class), app(GenerationRecorder::class));
        Http::assertNothingSent();

        (new AnalyzeImagesJob($this->post, [$done->id], force: true))->handle(app(AiWorkerClient::class), app(GenerationRecorder::class));
        Http::assertSentCount(1);
    }

    public function test_job_stores_results_and_marks_failures(): void
    {
        $a = $this->post->images()->create(['storage_key' => 'users/1/a.jpg', 'vision_status' => 'pending']);
        $b = $this->post->images()->create(['storage_key' => 'users/1/b.jpg', 'vision_status' => 'pending']);
        Http::fake(['*/images/analyze' => Http::response([
            'results' => [$this->vision($a->id)],
            'failed_ids' => [$b->id],
            'error' => '사진을 분석하지 못했습니다: billing',
            'prompt_version' => 'photo-analysis-v1',
            'generations' => [['provider' => 'anthropic', 'model' => 'claude-opus-5', 'status' => 'success', 'latency_ms' => 9000, 'input_tokens' => 6000, 'output_tokens' => 500]],
        ])]);

        (new AnalyzeImagesJob($this->post, [$a->id, $b->id]))->handle(app(AiWorkerClient::class), app(GenerationRecorder::class));

        Http::assertSent(fn ($r) => $r['images'][0] === ['id' => $a->id, 'storage_key' => 'users/1/a.jpg']
            && $r['facts'][0]['fact_value'] === '봉골레');
        $this->assertSame(['done', 'food'], [$a->fresh()->vision_status, $a->fresh()->vision_json['type']]);
        $this->assertArrayNotHasKey('id', $a->fresh()->vision_json);
        $this->assertSame('failed', $b->fresh()->vision_status);
        $this->assertStringContainsString('billing', $b->fresh()->vision_error);
        $this->assertSame('vision', Generation::sole()->purpose);

        $this->actingAs($this->post->user)->getJson("/api/posts/{$this->post->id}")
            ->assertJsonPath('data.images.0.vision.description', '오일 파스타로 보이는 음식')
            ->assertJsonPath('data.images.1.vision_status', 'failed');
    }

    public function test_job_failure_releases_pending_images(): void
    {
        $a = $this->post->images()->create(['storage_key' => 'a.jpg', 'vision_status' => 'pending']);

        (new AnalyzeImagesJob($this->post, [$a->id]))->failed(null);

        $this->assertSame('failed', $a->fresh()->vision_status);
    }

    public function test_plan_and_draft_receive_photo_analysis(): void
    {
        $a = $this->post->images()->create(['storage_key' => 'a.jpg', 'vision_json' => collect($this->vision(0, 'exterior'))->except('id')->all()]);
        $b = $this->post->images()->create(['storage_key' => 'b.jpg']);
        $this->post->forceFill(['plan_json' => ['outline' => [['heading' => 'h', 'purpose' => '', 'key_points' => [], 'fact_keys' => [], 'image_ids' => []]]]])->save();
        Http::fake([
            '*/posts/plan' => Http::response(['plan' => null, 'plan_error' => 'x', 'prompt_version' => 'v', 'generations' => []]),
            '*/posts/draft' => Http::response(['draft' => null, 'draft_error' => 'x', 'prompt_version' => 'v', 'generations' => []]),
        ]);

        (new GeneratePlanJob($this->post))->handle(app(AiWorkerClient::class), app(GenerationRecorder::class));
        (new GenerateDraftJob($this->post->fresh()))->handle(app(AiWorkerClient::class), app(GenerationRecorder::class), app(QualityGate::class));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/posts/plan')
            && $r['images'][0]['vision'] === ['type' => 'exterior', 'description' => '오일 파스타로 보이는 음식', 'usable' => true, 'suggested_section' => '메인 메뉴']
            && $r['images'][1]['vision'] === null);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/posts/draft')
            && $r['photo_notes'] === [(string) $a->id => ['type' => 'exterior', 'description' => '오일 파스타로 보이는 음식']]);
    }

    public function test_other_users_cannot_analyze(): void
    {
        $this->actingAs(Post::factory()->create()->user)->postJson("/api/posts/{$this->post->id}/images/analyze")->assertForbidden();
    }
}
