<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Jobs\GeneratePlanJob;
use App\Models\Generation;
use App\Models\Post;
use App\Models\PostImage;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\GenerationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PostPlanTest extends TestCase
{
    use RefreshDatabase;

    private Post $post;

    private PostImage $a;

    private PostImage $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->post = Post::factory()->create(['title' => null, 'tone' => 'friendly', 'target_length' => 1500]);
        $this->post->facts()->create(['fact_key' => '장소명', 'fact_value' => 'OO파스타', 'sort_order' => 0]);
        $this->post->facts()->create(['fact_key' => '가격', 'fact_value' => '19,000원', 'sort_order' => 1]);
        $this->a = $this->post->images()->create(['storage_key' => 'a.jpg', 'sort_order' => 0]);
        $this->b = $this->post->images()->create(['storage_key' => 'b.jpg', 'sort_order' => 1]);
    }

    private function plan(): array
    {
        return [
            'title_candidates' => ['인계동 파스타 OO파스타 후기', '두번째'],
            'search_intent' => '인계동 파스타집 찾기',
            'outline' => [
                ['heading' => '첫인상', 'purpose' => '도입', 'key_points' => [], 'fact_keys' => ['장소명'], 'image_ids' => [$this->a->id]],
                ['heading' => '가격', 'purpose' => '가격', 'key_points' => [], 'fact_keys' => ['가격'], 'image_ids' => []],
            ],
            'keywords' => ['primary' => ['인계동 파스타'], 'secondary' => []],
            'required_fact_keys' => ['장소명', '가격'],
            'forbidden_claims' => ['주차 가능 여부는 입력되지 않았으므로 단정하지 말 것'],
            'unused_fact_keys' => [],
            'unplaced_image_ids' => [$this->b->id],
            'corrections' => [],
        ];
    }

    private function runJob(array $response): void
    {
        Http::fake(['*/posts/plan' => Http::response($response)]);
        (new GeneratePlanJob($this->post))->handle(app(AiWorkerClient::class), app(GenerationRecorder::class));
    }

    private function generation(string $status = 'success'): array
    {
        return ['provider' => 'anthropic', 'model' => 'claude-opus-5', 'status' => $status, 'latency_ms' => 20000,
            'input_tokens' => 2000, 'output_tokens' => 1200, 'error_kind' => $status === 'success' ? null : 'billing'];
    }

    public function test_job_sends_post_context_and_saves_plan(): void
    {
        $this->post->project->analyses()->create([
            'analyzer_version' => 'x', 'stats_json' => ['reference_count' => 3], 'insight_json' => ['primary_intent' => '맛집 후기'],
        ]);

        $this->runJob(['plan' => $this->plan(), 'plan_error' => null, 'prompt_version' => 'writing-plan-v1', 'generations' => [$this->generation()]]);

        Http::assertSent(fn ($r) => $r['tone'] === 'friendly' && $r['target_length'] === 1500
            && $r['facts'][1] === ['fact_key' => '가격', 'fact_value' => '19,000원']
            && $r['images'][0]['id'] === $this->a->id
            && $r['analysis']['insight']['primary_intent'] === '맛집 후기');
        $post = $this->post->fresh();
        $this->assertSame(PostStatus::Planned, $post->status);
        $this->assertSame('인계동 파스타 OO파스타 후기', $post->title);
        $this->assertSame('첫인상', $post->plan_json['outline'][0]['heading']);
        $this->assertSame(['plan', $post->id, 'writing-plan-v1'], [Generation::sole()->purpose, Generation::sole()->post_id, Generation::sole()->prompt_version]);
    }

    public function test_llm_failure_marks_post_failed_with_reason(): void
    {
        $this->runJob(['plan' => null, 'plan_error' => '글 계획을 만들지 못했습니다: billing', 'prompt_version' => 'writing-plan-v1', 'generations' => [$this->generation('failed')]]);

        $post = $this->post->fresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertStringContainsString('billing', $post->plan_error);
        $this->assertSame('failed', Generation::sole()->status);
    }

    public function test_plan_endpoint_requires_facts_and_queues(): void
    {
        Queue::fake();
        $empty = Post::factory()->create();

        $this->actingAs($empty->user)->postJson("/api/posts/{$empty->id}/plan")->assertJsonValidationErrors('facts');

        $this->actingAs($this->post->user)->postJson("/api/posts/{$this->post->id}/plan")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'planning');
        $this->actingAs($this->post->user)->postJson("/api/posts/{$this->post->id}/plan")->assertStatus(202);
        Queue::assertPushed(GeneratePlanJob::class, 1);
    }

    public function test_update_plan_validates_facts_and_photos_and_recomputes_unused(): void
    {
        $this->post->forceFill(['plan_json' => $this->plan()])->save();
        $url = "/api/posts/{$this->post->id}/plan";
        $section = fn (array $facts, array $images) => ['heading' => '섹션', 'purpose' => null, 'key_points' => [], 'fact_keys' => $facts, 'image_ids' => $images];
        $foreign = Post::factory()->create()->images()->create(['storage_key' => 'z.jpg']);

        $this->actingAs($this->post->user)->putJson($url, ['outline' => [$section(['주차'], [])]])
            ->assertJsonValidationErrors('outline.0.fact_keys.0');
        $this->actingAs($this->post->user)->putJson($url, ['outline' => [$section([], [$foreign->id])]])
            ->assertJsonValidationErrors('outline.0.image_ids.0');
        $this->actingAs($this->post->user)->putJson($url, ['outline' => [$section([], [$this->a->id]), $section([], [$this->a->id])]])
            ->assertJsonValidationErrors('outline');

        $this->actingAs($this->post->user)->putJson($url, [
            'title' => '내가 고른 제목',
            'outline' => [$section(['가격'], [$this->b->id, $this->a->id])],
        ])
            ->assertOk()
            ->assertJsonPath('data.title', '내가 고른 제목')
            ->assertJsonPath('data.plan.unused_fact_keys', ['장소명'])
            ->assertJsonPath('data.plan.unplaced_image_ids', [])
            ->assertJsonPath('data.plan.forbidden_claims.0', '주차 가능 여부는 입력되지 않았으므로 단정하지 말 것');
    }

    public function test_plan_becomes_stale_when_referenced_facts_or_photos_disappear(): void
    {
        $this->post->forceFill(['plan_json' => $this->plan()])->save();
        $url = "/api/posts/{$this->post->id}";

        $this->actingAs($this->post->user)->getJson($url)->assertJsonPath('data.plan_stale', false);

        $this->actingAs($this->post->user)->patchJson($url, ['facts' => [['fact_key' => '장소명', 'fact_value' => 'OO파스타']]])
            ->assertJsonPath('data.plan_stale', true);
    }

    public function test_other_users_cannot_plan(): void
    {
        $stranger = Post::factory()->create()->user;

        $this->actingAs($stranger)->postJson("/api/posts/{$this->post->id}/plan")->assertForbidden();
        $this->actingAs($stranger)->putJson("/api/posts/{$this->post->id}/plan", ['outline' => []])->assertForbidden();
    }
}
