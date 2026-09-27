<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\Post;
use App\Models\PostImage;
use App\Support\PostContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostEditorTest extends TestCase
{
    use RefreshDatabase;

    private Post $post;

    private PostImage $image;

    protected function setUp(): void
    {
        parent::setUp();

        $this->post = Post::factory()->create(['title' => '제목', 'tone' => 'natural']);
        $this->post->project->update(['keyword' => '인계동 파스타']);
        $this->post->facts()->create(['fact_key' => '가격', 'fact_value' => '19,000원']);
        $this->image = $this->post->images()->create(['storage_key' => 'a.jpg']);
        $this->post->forceFill([
            'plan_json' => ['forbidden_claims' => ['주차 단정 금지']],
            'draft_meta_json' => ['char_count' => 1, 'target_length' => 2500, 'keyword_count' => 0, 'warnings' => [['code' => 'length', 'message' => 'x']]],
        ])->save();
    }

    private function blocks(): array
    {
        return [
            ['type' => 'heading', 'text' => '메뉴'],
            ['type' => 'paragraph', 'text' => '인계동 파스타 런치 세트'],
            ['type' => 'image', 'image_id' => $this->image->id],
            ['type' => 'list', 'items' => ['봉골레', '크림']],
            ['type' => 'quote', 'text' => '인계동 파스타 추천'],
        ];
    }

    public function test_saving_editor_content_recomputes_text_and_counts(): void
    {
        $this->actingAs($this->post->user)->patchJson("/api/posts/{$this->post->id}", [
            'content' => ['blocks' => $this->blocks(), 'tags' => ['인계동파스타']],
        ])
            ->assertOk()
            ->assertJsonPath('data.content.blocks.2', ['type' => 'image', 'image_id' => $this->image->id])
            ->assertJsonPath('data.draft_meta.keyword_count', 2)
            ->assertJsonPath('data.draft_meta.warnings.0.code', 'length');

        $post = $this->post->fresh();
        $this->assertSame("제목\n메뉴\n인계동 파스타 런치 세트\n- 봉골레\n- 크림\n인계동 파스타 추천", $post->content_text);
        $this->assertSame(mb_strlen('메뉴인계동 파스타 런치 세트- 봉골레- 크림인계동 파스타 추천'), $post->draft_meta_json['char_count']);
    }

    public function test_content_validation(): void
    {
        $foreign = Post::factory()->create()->images()->create(['storage_key' => 'z.jpg']);
        $url = "/api/posts/{$this->post->id}";

        $this->actingAs($this->post->user)->patchJson($url, ['content' => ['blocks' => [['type' => 'image', 'image_id' => $foreign->id]], 'tags' => []]])
            ->assertJsonValidationErrors('content.blocks.0.image_id');
        $this->actingAs($this->post->user)->patchJson($url, ['content' => ['blocks' => [['type' => 'script', 'text' => 'x']], 'tags' => []]])
            ->assertJsonValidationErrors('content.blocks.0.type');
        $this->actingAs($this->post->user)->patchJson($url, ['content' => ['blocks' => [['type' => 'image']], 'tags' => []]])
            ->assertJsonValidationErrors('content.blocks.0.image_id');
        $this->actingAs($this->post->user)->postJson('/api/posts', ['keyword_project_id' => $this->post->keyword_project_id, 'content' => ['blocks' => [], 'tags' => []]])
            ->assertJsonValidationErrors('content');
    }

    public function test_post_content_text_matches_worker_rules(): void
    {
        $this->assertSame("메뉴\n- a\n- b", PostContent::text(null, [
            ['type' => 'heading', 'text' => '메뉴'], ['type' => 'image', 'image_id' => 1], ['type' => 'list', 'items' => ['a', 'b']],
        ]));
    }

    public function test_rewrite_sends_post_context_and_records_generation(): void
    {
        Http::fake(['*/posts/rewrite' => Http::response([
            'text' => '런치 세트는 19,000원이었어요.',
            'warnings' => [],
            'error' => null,
            'prompt_version' => 'paragraph-rewrite-v1',
            'generations' => [['provider' => 'anthropic', 'model' => 'claude-opus-5', 'status' => 'success', 'latency_ms' => 4000, 'input_tokens' => 400, 'output_tokens' => 100]],
        ])]);

        $this->actingAs($this->post->user)->postJson("/api/posts/{$this->post->id}/rewrite", [
            'text' => '런치 세트 19,000원', 'instruction' => 'natural', 'before' => '앞 문단',
        ])->assertOk()->assertJsonPath('text', '런치 세트는 19,000원이었어요.');

        Http::assertSent(fn ($r) => $r['instruction'] === 'natural' && $r['tone'] === 'natural'
            && $r['facts'] === [['fact_key' => '가격', 'fact_value' => '19,000원']]
            && $r['forbidden_claims'] === ['주차 단정 금지']);
        $this->assertSame(['rewrite', $this->post->id], [Generation::sole()->purpose, Generation::sole()->post_id]);
    }

    public function test_rewrite_failure_and_validation(): void
    {
        Http::fake(['*/posts/rewrite' => Http::response(['text' => null, 'warnings' => [], 'error' => '문단을 다시 쓰지 못했습니다: billing', 'prompt_version' => 'v', 'generations' => []])]);
        $url = "/api/posts/{$this->post->id}/rewrite";

        $this->actingAs($this->post->user)->postJson($url, ['text' => 'x', 'instruction' => 'shorter'])
            ->assertStatus(503)->assertJsonPath('message', '문단을 다시 쓰지 못했습니다: billing');
        $this->actingAs($this->post->user)->postJson($url, ['text' => 'x', 'instruction' => 'funny'])->assertJsonValidationErrors('instruction');
        $this->actingAs(Post::factory()->create()->user)->postJson($url, ['text' => 'x', 'instruction' => 'shorter'])->assertForbidden();
    }
}
