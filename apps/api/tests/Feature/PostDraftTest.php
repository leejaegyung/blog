<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Jobs\GenerateDraftJob;
use App\Models\Generation;
use App\Models\Post;
use App\Models\PostImage;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\GenerationRecorder;
use App\Services\QualityGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PostDraftTest extends TestCase
{
    use RefreshDatabase;

    private Post $post;

    private PostImage $image;

    protected function setUp(): void
    {
        parent::setUp();

        $this->post = Post::factory()->create(['title' => '내가 고른 제목', 'tone' => 'clean', 'target_length' => 2500]);
        $this->post->facts()->create(['fact_key' => '가격', 'fact_value' => '19,000원']);
        $this->image = $this->post->images()->create(['storage_key' => 'a.jpg']);
        $this->post->forceFill(['status' => PostStatus::Planned, 'plan_json' => [
            'title_candidates' => ['후보'],
            'outline' => [['heading' => '메뉴', 'purpose' => '', 'key_points' => [], 'fact_keys' => ['가격'], 'image_ids' => [$this->image->id]]],
            'required_fact_keys' => ['가격'],
            'forbidden_claims' => [],
        ]])->save();
    }

    private function url(): string
    {
        return "/api/posts/{$this->post->id}/generate";
    }

    private function draftResponse(): array
    {
        return [
            'draft' => [
                'title' => '다듬은 제목',
                'blocks' => [['type' => 'heading', 'text' => '메뉴'], ['type' => 'paragraph', 'text' => '런치 세트 19,000원'], ['type' => 'image', 'image_id' => $this->image->id]],
                'tags' => ['인계동파스타'],
                'text' => "내가 고른 제목\n메뉴\n런치 세트 19,000원",
                'char_count' => 2400, 'target_length' => 2500, 'keyword_count' => 4,
                'warnings' => [['code' => 'unsupported_specific', 'message' => '입력하지 않은 시간 정보: 오전 11시']],
            ],
            'draft_error' => null,
            'prompt_version' => 'blog-draft-v1',
            'generations' => [['provider' => 'anthropic', 'model' => 'claude-opus-5', 'status' => 'success', 'latency_ms' => 60000, 'input_tokens' => 3000, 'output_tokens' => 4000]],
        ];
    }

    private function runJob(array $response): void
    {
        Http::fake(['*/posts/draft' => Http::response($response)]);
        (new GenerateDraftJob($this->post))->handle(app(AiWorkerClient::class), app(GenerationRecorder::class), app(QualityGate::class));
    }

    public function test_job_saves_draft_blocks_meta_and_keeps_chosen_title(): void
    {
        $this->runJob($this->draftResponse());

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/posts/draft') && $r['title'] === '내가 고른 제목' && $r['tone'] === 'clean'
            && $r['image_ids'] === [$this->image->id] && $r['plan']['outline'][0]['heading'] === '메뉴');
        $post = $this->post->fresh();
        $this->assertSame(PostStatus::Review, $post->status);
        $this->assertSame('내가 고른 제목', $post->title);
        $this->assertSame('paragraph', $post->content_json['blocks'][1]['type']);
        $this->assertSame(['인계동파스타'], $post->content_json['tags']);
        $this->assertSame($post->content_json, $post->content_original_json);
        $this->assertSame(2400, $post->draft_meta_json['char_count']);
        $this->assertSame('blog-draft-v1', $post->generation_version);
        $this->assertSame('draft', Generation::sole()->purpose);
    }

    public function test_job_sends_keyword_hashtags_custom_first_then_analysis(): void
    {
        $this->post->project->analyses()->create(['analyzer_version' => 'x', 'guide_json' => ['hashtags' => [['tag' => '분석태그', 'source' => 'ai']]]]);
        $this->runJob($this->draftResponse());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/posts/draft') && $r['hashtags'] === ['분석태그']);

        $this->post->project->forceFill(['hashtags_json' => ['내태그']])->save();
        $this->post->forceFill(['status' => PostStatus::Planned])->save();
        $this->runJob($this->draftResponse());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/posts/draft') && $r['hashtags'] === ['내태그']);
    }

    public function test_llm_failure_keeps_plan_and_records_error(): void
    {
        $this->runJob(['draft' => null, 'draft_error' => '초안을 만들지 못했습니다: billing', 'prompt_version' => 'blog-draft-v1', 'generations' => []]);

        $post = $this->post->fresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertStringContainsString('billing', $post->draft_error);
        $this->assertNotNull($post->plan_json);
    }

    public function test_generate_endpoint_queues_once(): void
    {
        Queue::fake();

        $this->actingAs($this->post->user)->postJson($this->url())->assertStatus(202)->assertJsonPath('data.status', 'generating');
        $this->actingAs($this->post->user)->postJson($this->url())->assertStatus(202);

        Queue::assertPushed(GenerateDraftJob::class, 1);
    }

    public function test_generate_requires_fresh_plan(): void
    {
        Queue::fake();

        $this->post->facts()->delete();
        $this->actingAs($this->post->user)->postJson($this->url())->assertJsonValidationErrors('plan');

        $this->post->forceFill(['plan_json' => null])->save();
        $this->actingAs($this->post->user)->postJson($this->url())
            ->assertJsonValidationErrors(['plan' => '먼저 글 계획을 만들어 주세요.']);

        Queue::assertNothingPushed();
    }

    public function test_resource_exposes_content_and_meta(): void
    {
        $this->runJob($this->draftResponse());

        $this->actingAs($this->post->user)->getJson("/api/posts/{$this->post->id}")
            ->assertJsonPath('data.content.blocks.0.text', '메뉴')
            ->assertJsonPath('data.draft_meta.warnings.0.code', 'unsupported_specific');
    }

    public function test_other_users_cannot_generate(): void
    {
        $this->actingAs(Post::factory()->create()->user)->postJson($this->url())->assertForbidden();
    }
}
