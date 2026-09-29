<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeKeywordJob;
use App\Jobs\GenerateDraftJob;
use App\Jobs\GeneratePlanJob;
use App\Jobs\StartTwinJob;
use App\Models\KeywordProject;
use App\Models\Post;
use App\Models\User;
use App\Services\TwinPosts;
use App\Support\TextOverlap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 네이버·티스토리 동시에 올리기: 같은 경험으로 플랫폼마다 따로 쓴 짝 글을 만든다(그대로 두 곳에 올리면 중복 문서). */
class TwinPostTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
        $this->user = User::factory()->create();
    }

    private function category(string $platform): KeywordProject
    {
        return $this->user->keywordProjects()->create(['keyword' => "맛집-{$platform}", 'kind' => KeywordProject::KIND_CATEGORY, 'platform' => $platform]);
    }

    /** 사실 2개·사진 2장(분석 끝남)이 있는 네이버 글 */
    private function primaryPost(): Post
    {
        $project = $this->user->keywordProjects()->create(['keyword' => '인계동 파스타']);
        $post = $this->user->posts()->create(['keyword_project_id' => $project->id, 'platform' => 'naver', 'tone' => 'natural', 'target_length' => 2500]);
        $post->facts()->create(['fact_key' => '가격', 'fact_value' => '18000원', 'sort_order' => 0]);
        $post->facts()->create(['fact_key' => '주차', 'fact_value' => '가능', 'sort_order' => 1]);
        foreach (['a', 'b'] as $i => $name) {
            Storage::disk('uploads')->put("p/{$name}.jpg", strtoupper($name));
            Storage::disk('uploads')->put("p/{$name}_t.jpg", 'thumb');
            $post->images()->create(['storage_key' => "p/{$name}.jpg", 'thumb_key' => "p/{$name}_t.jpg", 'sort_order' => $i, 'vision_json' => ['type' => 'food'], 'vision_status' => 'done']);
        }

        return $post;
    }

    public function test_start_with_both_creates_a_naver_post_and_a_tistory_twin(): void
    {
        $naver = $this->category('naver');
        $tistory = $this->category('tistory');

        $this->actingAs($this->user)->postJson('/api/posts/start', ['keyword' => '파스타', 'platform' => 'both', 'twin_learning_category_id' => $naver->id])
            ->assertJsonValidationErrors('twin_learning_category_id');

        $primary = $this->actingAs($this->user)->postJson('/api/posts/start', [
            'keyword' => '파스타', 'platform' => 'both', 'learning_category_id' => $naver->id, 'twin_learning_category_id' => $tistory->id,
        ])->assertCreated()->assertJsonPath('data.platform', 'naver')->json('data');

        $twin = Post::where('twin_of_post_id', $primary['id'])->sole();
        $this->assertSame('tistory', $twin->platform);
        $this->assertSame($tistory->id, $twin->project->learning_category_id);
        $this->assertSame($twin->id, $primary['twin_post_id']);

        // 티스토리만 쓰는 새 글이 빈 짝 글을 가로채지 않는다
        $alone = $this->actingAs($this->user)->postJson('/api/posts/start', ['keyword' => '파스타', 'platform' => 'tistory'])->assertCreated()->json('data');
        $this->assertNotSame($twin->id, $alone['id']);
    }

    public function test_autopilot_of_the_original_starts_the_twin_after_photo_analysis(): void
    {
        Bus::fake();
        $post = $this->primaryPost();
        app(TwinPosts::class)->ensure($post);
        $post->images()->first()->update(['vision_json' => null]);

        $this->actingAs($this->user)->postJson("/api/posts/{$post->id}/autopilot", ['until' => 'plan'])->assertAccepted();

        Bus::assertChained([\App\Jobs\AnalyzeImagesJob::class, StartTwinJob::class, AnalyzeKeywordJob::class, GeneratePlanJob::class]);
    }

    public function test_twin_copies_facts_and_photos_and_writes_its_own_draft(): void
    {
        $post = $this->primaryPost();
        $twin = app(TwinPosts::class)->ensure($post);
        Bus::fake();

        (new StartTwinJob($post))->handle(app(TwinPosts::class), app(\App\Services\Autopilot::class));

        $twin->refresh();
        $this->assertSame(['가격=18000원', '주차=가능'], $twin->facts->map(fn ($f) => "{$f->fact_key}={$f->fact_value}")->all());
        $this->assertCount(2, $twin->images);
        $copy = $twin->images->first();
        // 파일은 따로 복사하고, 사진 분석 결과도 가져와 다시 분석하지 않는다
        $this->assertStringStartsWith("users/{$this->user->id}/posts/{$twin->id}/", $copy->storage_key);
        $this->assertSame('A', Storage::disk('uploads')->get($copy->storage_key));
        $this->assertSame(['type' => 'food'], $copy->vision_json);
        $this->assertSame('running', $twin->pipeline_status);
        Bus::assertChained([AnalyzeKeywordJob::class, GeneratePlanJob::class, GenerateDraftJob::class]);

        // 바뀐 게 없으면 다시 쓰지 않는다
        $this->assertFalse(app(TwinPosts::class)->sync($post, $twin));

        // 원래 글에서 사진을 빼면 짝 글에서도 빠지고 파일도 지운다(원래 파일은 그대로)
        $post->images()->where('storage_key', 'p/a.jpg')->delete();
        $this->assertTrue(app(TwinPosts::class)->sync($post, $twin->refresh()));
        $this->assertCount(1, $twin->refresh()->images);
        Storage::disk('uploads')->assertMissing($copy->storage_key);
        Storage::disk('uploads')->assertExists('p/b.jpg');
    }

    public function test_an_edited_twin_is_not_overwritten_automatically(): void
    {
        $post = $this->primaryPost();
        $twin = app(TwinPosts::class)->ensure($post);
        app(TwinPosts::class)->sync($post, $twin);
        $twin->forceFill(['content_json' => ['blocks' => [['type' => 'paragraph', 'text' => '고친 글']], 'tags' => []], 'content_original_json' => ['blocks' => [], 'tags' => []], 'pipeline_status' => 'done'])->save();
        $post->facts()->create(['fact_key' => '웨이팅', 'fact_value' => '30분', 'sort_order' => 2]);
        Bus::fake();

        (new StartTwinJob($post))->handle(app(TwinPosts::class), app(\App\Services\Autopilot::class));
        Bus::assertNothingDispatched();
        $this->assertCount(3, $twin->refresh()->facts);

        // 6단계 "다시 쓰기"는 고친 글이어도 다시 쓴다
        (new StartTwinJob($post, force: true))->handle(app(TwinPosts::class), app(\App\Services\Autopilot::class));
        Bus::assertChained([AnalyzeKeywordJob::class, GeneratePlanJob::class, GenerateDraftJob::class]);
    }

    public function test_twin_endpoints_create_show_overlap_and_reject_twin_of_twin(): void
    {
        Bus::fake();
        $post = $this->primaryPost();
        $post->forceFill(['content_text' => '인계동 파스타 집에 다녀왔어요 가격은 18000원이고 주차도 가능했어요'])->save();
        $tistory = $this->category('tistory');

        $this->actingAs($this->user)->postJson("/api/posts/{$post->id}/twin", ['learning_category_id' => $this->category('naver')->id])
            ->assertJsonValidationErrors('learning_category_id');
        $twinId = $this->actingAs($this->user)->postJson("/api/posts/{$post->id}/twin", ['learning_category_id' => $tistory->id])
            ->assertAccepted()->assertJsonPath('data.platform', 'tistory')->assertJsonPath('data.twin_of_post_id', $post->id)->json('data.id');
        Bus::assertDispatched(StartTwinJob::class, fn ($job) => $job->force);

        $this->actingAs($this->user)->postJson("/api/posts/{$twinId}/twin")->assertJsonValidationErrors('post');

        Post::find($twinId)->forceFill(['content_text' => '인계동 파스타 집에 다녀왔어요 가격은 18000원이고 분위기가 좋았어요'])->save();
        $overlap = $this->actingAs($this->user)->getJson("/api/posts/{$post->id}/twin")->assertJsonPath('data.id', $twinId)->json('overlap');
        $this->assertGreaterThan(0.5, $overlap);
        $this->actingAs($this->user)->getJson("/api/posts/{$twinId}/twin")->assertJsonPath('data.id', $post->id);

        $this->actingAs($this->user)->getJson('/api/posts?platform=tistory')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $twinId);
    }

    public function test_plan_and_draft_tell_the_ai_to_write_a_distinct_version(): void
    {
        $post = $this->primaryPost();
        app(TwinPosts::class)->ensure($post);
        \Illuminate\Support\Facades\Http::fake(['*/posts/plan' => \Illuminate\Support\Facades\Http::response(['plan' => null, 'plan_error' => 'x', 'prompt_version' => 'v', 'generations' => []])]);

        dispatch_sync(new GeneratePlanJob($post));

        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r['twin'] === true && $r['platform'] === 'naver');
    }

    public function test_overlap_is_low_for_independent_writing_and_high_for_copies(): void
    {
        $a = '수원 인계동 파스타 맛집에 다녀왔어요. 크림 파스타가 진하고 면이 쫄깃했어요.';
        $this->assertSame(1.0, TextOverlap::ratio($a, $a));
        $this->assertLessThan(0.2, TextOverlap::ratio($a, '인계동에서 점심으로 파스타를 먹었습니다. 소스는 꾸덕했고 양이 넉넉한 편이었습니다.'));
        $this->assertNull(TextOverlap::ratio($a, ''));
    }
}
