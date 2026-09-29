<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BlogSettingsController;
use App\Jobs\AnalyzeKeywordJob;
use App\Jobs\ParseReferenceJob;
use App\Models\AppSetting;
use App\Models\KeywordProject;
use App\Models\Post;
use App\Models\User;
use App\Services\KakaoSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** 네이버·티스토리 분리: 카테고리·키워드·글이 올릴 곳별로 따로 있고, 티스토리는 카카오 검색으로 상위 글을 찾아 학습한다. */
class TistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function category(string $platform, string $name = '맛집'): KeywordProject
    {
        return $this->user->keywordProjects()->create(['keyword' => $name, 'kind' => KeywordProject::KIND_CATEGORY, 'platform' => $platform]);
    }

    public function test_categories_are_separate_per_platform(): void
    {
        $this->actingAs($this->user)->postJson('/api/projects', ['keyword' => '맛집', 'kind' => 'category'])
            ->assertCreated()->assertJsonPath('data.platform', 'naver');
        // 같은 이름이라도 티스토리용은 따로 만들 수 있다
        $this->actingAs($this->user)->postJson('/api/projects', ['keyword' => '맛집', 'kind' => 'category', 'platform' => 'tistory'])
            ->assertCreated()->assertJsonPath('data.platform', 'tistory');
        $this->actingAs($this->user)->postJson('/api/projects', ['keyword' => '맛집', 'kind' => 'category', 'platform' => 'tistory'])
            ->assertJsonValidationErrors('keyword');
        $this->actingAs($this->user)->postJson('/api/projects', ['keyword' => '여행', 'kind' => 'category', 'platform' => 'daum'])
            ->assertJsonValidationErrors('platform');

        $this->actingAs($this->user)->getJson('/api/projects?kind=category&platform=tistory')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.platform', 'tistory');
        $this->actingAs($this->user)->getJson('/api/projects?kind=category')->assertJsonCount(2, 'data');
        // 만든 뒤에는 올릴 곳을 바꾸지 않는다
        $id = $this->actingAs($this->user)->getJson('/api/projects?kind=category&platform=naver')->json('data.0.id');
        $this->actingAs($this->user)->putJson("/api/projects/{$id}", ['platform' => 'tistory'])->assertJsonValidationErrors('platform');
    }

    public function test_start_keeps_keywords_and_posts_per_platform_and_checks_the_category(): void
    {
        $naver = $this->category('naver');
        $tistory = $this->category('tistory');

        $this->actingAs($this->user)->postJson('/api/posts/start', ['keyword' => '인계동 파스타', 'platform' => 'tistory', 'learning_category_id' => $naver->id])
            ->assertJsonValidationErrors('learning_category_id');

        $post = $this->actingAs($this->user)->postJson('/api/posts/start', ['keyword' => '인계동 파스타', 'platform' => 'tistory', 'learning_category_id' => $tistory->id])
            ->assertCreated()->assertJsonPath('data.platform', 'tistory')->json('data');
        $naverPost = $this->actingAs($this->user)->postJson('/api/posts/start', ['keyword' => '인계동 파스타'])
            ->assertCreated()->assertJsonPath('data.platform', 'naver')->json('data');

        // 같은 키워드라도 네이버용·티스토리용 분석이 따로다
        $this->assertNotSame($post['keyword_project_id'], $naverPost['keyword_project_id']);
        $this->assertSame('tistory', KeywordProject::find($post['keyword_project_id'])->platform);
    }

    public function test_analysis_sends_the_platform_and_it_changes_the_cache_key(): void
    {
        $project = $this->user->keywordProjects()->create(['keyword' => '파스타', 'platform' => 'tistory']);
        Http::fake(['*/keywords/analyze' => Http::response(['stats' => null, 'insight' => null, 'insight_error' => 'x', 'guide' => null, 'prompt_version' => 'v', 'generations' => []])]);

        dispatch_sync(new AnalyzeKeywordJob($project));

        Http::assertSent(fn ($r) => $r['platform'] === 'tistory');
        $hash = AnalyzeKeywordJob::sourceHash($project);
        $project->platform = 'naver';
        $this->assertNotSame($hash, AnalyzeKeywordJob::sourceHash($project));
    }

    public function test_blog_settings_normalize_the_tistory_address_and_encrypt_the_kakao_key(): void
    {
        $this->actingAs($this->user)->putJson('/api/settings/blogs', ['tistory_host' => 'https://MyBlog.tistory.com/123'])
            ->assertOk()->assertJsonPath('data.tistory_host', 'myblog.tistory.com')->assertJsonPath('data.kakao_ready', false);
        $this->actingAs($this->user)->putJson('/api/settings/blogs', ['tistory_host' => 'other'])->assertJsonPath('data.tistory_host', 'other.tistory.com');
        $this->actingAs($this->user)->putJson('/api/settings/blogs', ['kakao_key' => 'abc123DEF'])->assertJsonPath('data.kakao_ready', true)
            // 키 자체는 화면에 돌려주지 않는다
            ->assertJsonMissingPath('data.kakao_key');

        $this->assertNotSame('abc123DEF', AppSetting::find(BlogSettingsController::KAKAO_KEY)->value);
        $this->assertSame('abc123DEF', BlogSettingsController::kakaoKey());
        $this->actingAs($this->user)->getJson('/api/user')
            ->assertJsonPath('meta.tistory_host', 'other.tistory.com')->assertJsonPath('meta.kakao_ready', true);

        $this->actingAs($this->user)->putJson('/api/settings/blogs', ['kakao_key' => 'no spaces!'])->assertJsonValidationErrors('kakao_key');
        $this->actingAs($this->user)->putJson('/api/settings/blogs', ['kakao_key' => ''])->assertJsonPath('data.kakao_ready', false);
    }

    public function test_kakao_search_keeps_only_tistory_post_addresses(): void
    {
        AppSetting::create(['key' => BlogSettingsController::KAKAO_KEY, 'value' => encrypt('k', false)]);
        Http::fake([KakaoSearch::ENDPOINT.'*' => Http::response(['meta' => ['is_end' => true], 'documents' => [
            ['url' => 'https://a.tistory.com/12', 'title' => '<b>파스타</b> 후기 &amp; 추천', 'blogname' => 'A'],
            ['url' => 'https://a.tistory.com/12/', 'title' => '중복', 'blogname' => 'A'],
            ['url' => 'https://blog.naver.com/x/1', 'title' => '네이버', 'blogname' => 'N'],
            ['url' => 'https://b.tistory.com/manage/newpost', 'title' => '관리', 'blogname' => 'B'],
            ['url' => 'http://c.tistory.com/entry/파스타', 'title' => '씨', 'blogname' => 'C'],
        ]])]);

        $result = (new KakaoSearch)->tistoryPosts('파스타', 10);

        $this->assertNull($result['error']);
        $this->assertSame(['https://a.tistory.com/12', 'https://c.tistory.com/entry/파스타'], array_column($result['posts'], 'url'));
        $this->assertSame('파스타 후기 & 추천', $result['posts'][0]['title']);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'KakaoAK k') && $r['sort'] === 'accuracy');
        // 검색어 그대로 찾은 뒤 모자라면 "티스토리"를 붙여 채운다
        Http::assertSent(fn ($r) => $r['query'] === '파스타 티스토리');
        $this->assertCount(2, (new KakaoSearch)->tistoryPosts('파스타 티스토리', 10)['posts']);
        Http::assertSentCount(3);
    }

    public function test_discover_adds_top_tistory_posts_and_schedules_learning(): void
    {
        Queue::fake();
        AppSetting::create(['key' => BlogSettingsController::KAKAO_KEY, 'value' => encrypt('k', false)]);
        Http::fake([KakaoSearch::ENDPOINT.'*' => Http::response(['meta' => ['is_end' => true], 'documents' => [
            ['url' => 'https://a.tistory.com/1', 'title' => '하나', 'blogname' => 'A'],
            ['url' => 'https://b.tistory.com/2', 'title' => '둘', 'blogname' => 'B'],
        ]])]);
        $category = $this->category('tistory');
        $category->references()->create(['source_type' => 'user_url', 'source_url' => 'https://a.tistory.com/1', 'usage_permission' => 'user_provided', 'parse_status' => 'parsed']);

        $this->actingAs($this->user)->postJson("/api/projects/{$category->id}/discover", ['query' => '수원 맛집'])
            ->assertCreated()->assertJsonPath('found', 2)->assertJsonCount(1, 'data')->assertJsonCount(1, 'skipped')
            ->assertJsonPath('data.0.title', '둘')->assertJsonPath('data.0.source_type', 'kakao_search')->assertJsonPath('learning', true);

        Queue::assertPushed(ParseReferenceJob::class, 1);
        Queue::assertPushed(AnalyzeKeywordJob::class, fn ($job) => $job->delay !== null);
        $this->assertSame('analyzing', $category->refresh()->status->value);
    }

    public function test_discover_is_only_for_tistory_and_needs_a_key(): void
    {
        Http::fake();
        $this->actingAs($this->user)->postJson('/api/projects/'.$this->category('naver')->id.'/discover', ['query' => '맛집'])
            ->assertJsonValidationErrors('project');
        $this->actingAs($this->user)->postJson('/api/projects/'.$this->category('tistory')->id.'/discover', ['query' => '맛집'])
            ->assertJsonValidationErrors('query');
        Http::assertNothingSent();
    }

    public function test_tistory_export_keeps_tags_out_of_the_body_and_publish_records_the_tistory_address(): void
    {
        $post = Post::factory()->create(['title' => '제목', 'platform' => 'tistory']);
        $post->forceFill(['content_json' => ['blocks' => [['type' => 'paragraph', 'text' => '본문']], 'tags' => ['파스타', '수원']]])->save();

        $this->actingAs($post->user)->postJson("/api/posts/{$post->id}/export")->assertOk()
            ->assertJsonPath('data.html', '<p>본문</p>')->assertJsonPath('data.text', "제목\n\n본문")->assertJsonPath('data.tags', ['파스타', '수원']);

        // 네이버에도 올리려고 네이버 탭에서 만들면 본문 끝에 해시태그가 들어간다(글의 올릴 곳은 그대로)
        $this->actingAs($post->user)->postJson("/api/posts/{$post->id}/export", ['platform' => 'naver'])
            ->assertJsonPath('data.html', "<p>본문</p>\n<p>#파스타 #수원</p>");
        $this->assertSame('tistory', $post->refresh()->platform);

        $this->actingAs($post->user)->postJson("/api/posts/{$post->id}/publish", ['published_url' => 'https://myblog.tistory.com/7'])
            ->assertOk()->assertJsonPath('data.tistory_url', 'https://myblog.tistory.com/7')->assertJsonPath('data.published_url', null);
        // 같은 글을 네이버에도 올리면 둘 다 남는다
        $this->actingAs($post->user)->postJson("/api/posts/{$post->id}/publish", ['published_url' => 'https://blog.naver.com/me/1'])
            ->assertJsonPath('data.published_url', 'https://blog.naver.com/me/1')->assertJsonPath('data.tistory_url', 'https://myblog.tistory.com/7');
    }
}
