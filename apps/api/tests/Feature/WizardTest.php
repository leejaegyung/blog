<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Jobs\AnalyzeImagesJob;
use App\Jobs\AnalyzeKeywordJob;
use App\Jobs\GenerateDraftJob;
use App\Jobs\GeneratePlanJob;
use App\Models\KeywordProject;
use App\Models\Post;
use App\Models\User;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\GenerationRecorder;
use App\Services\QualityGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WizardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_start_reuses_project_by_keyword(): void
    {
        $first = $this->actingAs($this->user)->postJson('/api/posts/start', ['keyword' => ' 수원  인계동 파스타 ', 'category' => '맛집'])
            ->assertCreated()
            ->assertJsonPath('data.keyword', '수원 인계동 파스타')
            ->assertJsonPath('data.tone', 'natural');
        $this->actingAs($this->user)->postJson('/api/posts/start', ['keyword' => '수원 인계동 파스타'])->assertCreated();

        $this->assertSame(1, KeywordProject::count());
        $this->assertSame(2, Post::where('keyword_project_id', $first->json('data.keyword_project_id'))->count());
        $this->actingAs($this->user)->postJson('/api/posts/start', ['keyword' => '   '])->assertJsonValidationErrors('keyword');
    }

    private function makePost(): Post
    {
        $id = $this->actingAs($this->user)->postJson('/api/posts/start', ['keyword' => '인계동 파스타'])->json('data.id');
        $post = Post::find($id);
        $post->facts()->create(['fact_key' => '가격', 'fact_value' => '19,000원']);

        return $post;
    }

    public function test_autopilot_chains_only_needed_steps(): void
    {
        Bus::fake();
        $post = $this->makePost();
        $new = $post->images()->create(['storage_key' => 'a.jpg']);
        $post->images()->create(['storage_key' => 'b.jpg', 'vision_json' => ['type' => 'food']]);

        $this->actingAs($this->user)->postJson("/api/posts/{$post->id}/autopilot")
            ->assertStatus(202)
            ->assertJsonPath('data.pipeline_status', 'running')
            ->assertJsonPath('data.pipeline_step', 'vision');

        Bus::assertChained([
            fn (AnalyzeImagesJob $job) => $job->imageIds === [$new->id] && $job->pipelinePostId === $post->id,
            AnalyzeKeywordJob::class,
            GeneratePlanJob::class,
            GenerateDraftJob::class,
        ]);
        $this->assertSame('pending', $new->fresh()->vision_status);
    }

    public function test_autopilot_includes_photos_still_being_analyzed(): void
    {
        Bus::fake();
        $post = $this->makePost();
        $pending = $post->images()->create(['storage_key' => 'a.jpg', 'vision_status' => 'pending']);

        $this->actingAs($this->user)->postJson("/api/posts/{$post->id}/autopilot")->assertStatus(202);

        Bus::assertChained([
            fn (AnalyzeImagesJob $job) => $job->imageIds === [$pending->id] && ! $job->force,
            AnalyzeKeywordJob::class,
            GeneratePlanJob::class,
            GenerateDraftJob::class,
        ]);
    }

    public function test_autopilot_skips_fresh_analysis_and_ignores_double_click(): void
    {
        Bus::fake();
        $post = $this->makePost();
        $post->project->analyses()->create([
            'analyzer_version' => 'x', 'insight_json' => ['primary_intent' => 'x'], 'expires_at' => now()->addDay(),
            'source_hash' => AnalyzeKeywordJob::sourceHash($post->project),
        ]);

        $this->actingAs($this->user)->postJson("/api/posts/{$post->id}/autopilot")->assertJsonPath('data.pipeline_step', 'plan');
        $this->actingAs($this->user)->postJson("/api/posts/{$post->id}/autopilot")->assertStatus(202);

        Bus::assertChained([GeneratePlanJob::class, GenerateDraftJob::class]);
        Bus::assertDispatchedTimes(GeneratePlanJob::class, 1);
    }

    public function test_autopilot_until_plan_stops_before_draft_and_finishes_after_plan(): void
    {
        Bus::fake();
        $post = $this->makePost();

        $this->actingAs($this->user)->postJson("/api/posts/{$post->id}/autopilot", ['until' => 'plan'])->assertStatus(202);

        Bus::assertChained([
            AnalyzeKeywordJob::class,
            fn (GeneratePlanJob $job) => $job->finishPipeline === true,
        ]);
        Bus::assertNotDispatched(GenerateDraftJob::class);
    }

    public function test_plan_as_last_step_marks_pipeline_done(): void
    {
        $post = $this->makePost();
        $post->forceFill(['pipeline_status' => 'running'])->save();
        Http::fake(['*/posts/plan' => Http::response(['plan' => ['title_candidates' => ['t'], 'outline' => []], 'plan_error' => null, 'prompt_version' => 'v', 'generations' => []])]);

        (new GeneratePlanJob($post, finishPipeline: true))->inPipeline($post->id)->handle(app(AiWorkerClient::class), app(GenerationRecorder::class));

        $this->assertSame(['done', PostStatus::Planned], [$post->fresh()->pipeline_status, $post->fresh()->status]);
    }

    public function test_autopilot_requires_facts(): void
    {
        $id = $this->actingAs($this->user)->postJson('/api/posts/start', ['keyword' => '파스타'])->json('data.id');

        $this->actingAs($this->user)->postJson("/api/posts/{$id}/autopilot")->assertJsonValidationErrors('facts');
        $this->actingAs(User::factory()->create())->postJson("/api/posts/{$id}/autopilot")->assertForbidden();
    }

    public function test_plan_failure_stops_pipeline_and_draft_is_skipped(): void
    {
        $post = $this->makePost();
        $post->forceFill(['pipeline_status' => 'running'])->save();
        Http::fake([
            '*/posts/plan' => Http::response(['plan' => null, 'plan_error' => '글 계획을 만들지 못했습니다: billing', 'prompt_version' => 'v', 'generations' => []]),
            '*/posts/draft' => Http::response(['draft' => null, 'draft_error' => 'x', 'prompt_version' => 'v', 'generations' => []]),
        ]);

        (new GeneratePlanJob($post))->inPipeline($post->id)->handle(app(AiWorkerClient::class), app(GenerationRecorder::class));
        (new GenerateDraftJob($post))->inPipeline($post->id)->handle(app(AiWorkerClient::class), app(GenerationRecorder::class), app(QualityGate::class));

        $post->refresh();
        $this->assertSame(['failed', 'plan'], [$post->pipeline_status, $post->pipeline_step]);
        $this->assertStringContainsString('billing', $post->pipeline_error);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/posts/draft'));
    }

    public function test_successful_pipeline_ends_done_after_quality_check(): void
    {
        $post = $this->makePost();
        $post->forceFill(['pipeline_status' => 'running', 'plan_json' => ['outline' => [], 'title_candidates' => ['t']]])->save();
        Http::fake([
            '*/posts/draft' => Http::response(['draft' => ['title' => 't', 'blocks' => [['type' => 'paragraph', 'text' => '본문']], 'tags' => [],
                'text' => 't', 'char_count' => 2, 'target_length' => 2500, 'keyword_count' => 0, 'warnings' => []],
                'draft_error' => null, 'prompt_version' => 'blog-draft-v2', 'generations' => []]),
            '*/posts/quality-check' => Http::response(['score' => 80, 'issues' => [], 'parts' => [], 'metrics' => []]),
        ]);

        (new GenerateDraftJob($post))->inPipeline($post->id)->handle(app(AiWorkerClient::class), app(GenerationRecorder::class), app(QualityGate::class));

        $post->refresh();
        $this->assertSame(['done', null, PostStatus::Review], [$post->pipeline_status, $post->pipeline_step, $post->status]);
        $this->assertSame(80, $post->quality_json['score']);
    }

    public function test_post_list_includes_keyword(): void
    {
        $this->makePost();

        $this->actingAs($this->user)->getJson('/api/posts')->assertJsonPath('data.0.keyword', '인계동 파스타');
    }
}
