<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeKeywordJob;
use App\Models\KeywordProject;
use App\Models\ReferenceDocument;
use App\Models\User;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\GenerationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 관리 › 카테고리별 학습: 카테고리를 만들어 참고 글로 학습시키고, 글쓰기에서 골라 쓴다. */
class LearningCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function category(string $name = '맛집'): KeywordProject
    {
        return $this->user->keywordProjects()->create(['keyword' => $name, 'kind' => KeywordProject::KIND_CATEGORY]);
    }

    private function reference(KeywordProject $project, string $hash): ReferenceDocument
    {
        $reference = $project->references()->create([
            'source_type' => 'user_url', 'source_url' => "https://ex.com/{$hash}", 'usage_permission' => 'user_provided',
            'parse_status' => 'parsed', 'content_hash' => $hash,
        ]);
        $reference->features()->create([
            'char_count' => 1000, 'analyzer_version' => AnalyzeKeywordJob::FEATURES_VERSION,
            'features_json' => ['version' => AnalyzeKeywordJob::FEATURES_VERSION, 'layout' => $hash],
        ]);

        return $reference;
    }

    public function test_categories_are_listed_separately_and_names_are_unique_per_kind(): void
    {
        $this->actingAs($this->user)->postJson('/api/projects', ['keyword' => '맛집', 'kind' => 'category'])
            ->assertCreated()->assertJsonPath('data.kind', 'category');
        // 같은 이름의 키워드는 따로 있을 수 있고, 같은 이름의 카테고리는 안 된다
        $this->actingAs($this->user)->postJson('/api/projects', ['keyword' => '맛집'])->assertCreated()->assertJsonPath('data.kind', 'keyword');
        $this->actingAs($this->user)->postJson('/api/projects', ['keyword' => '맛집', 'kind' => 'category'])->assertJsonValidationErrors('keyword');

        $this->actingAs($this->user)->getJson('/api/projects?kind=category')->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'category');
        $this->actingAs($this->user)->getJson('/api/projects')->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'keyword');
    }

    public function test_start_uses_the_chosen_learning_category(): void
    {
        $category = $this->category();

        $response = $this->actingAs($this->user)->postJson('/api/posts/start', ['keyword' => '인계동 파스타', 'learning_category_id' => $category->id])
            ->assertCreated()
            ->assertJsonPath('data.learning_category.keyword', '맛집');

        $project = KeywordProject::find($response->json('data.keyword_project_id'));
        $this->assertSame([KeywordProject::KIND_KEYWORD, $category->id, '맛집'], [$project->kind, $project->learning_category_id, $project->category]);

        $this->actingAs($this->user)->getJson("/api/posts?learning_category_id={$category->id}")->assertJsonCount(1, 'data');
        $this->actingAs($this->user)->getJson('/api/projects?kind=category')->assertJsonPath('data.0.post_count', 1);

        $other = User::factory()->create()->keywordProjects()->create(['keyword' => '카페', 'kind' => KeywordProject::KIND_CATEGORY]);
        $this->actingAs($this->user)->postJson('/api/posts/start', ['keyword' => '카페 투어', 'learning_category_id' => $other->id])
            ->assertJsonValidationErrors('learning_category_id');
        $this->actingAs($this->user)->postJson('/api/posts/start', ['keyword' => '카페 투어', 'learning_category_id' => $project->id])
            ->assertJsonValidationErrors('learning_category_id');
    }

    public function test_keyword_analysis_learns_from_its_category_references(): void
    {
        $category = $this->category();
        $keyword = $this->user->keywordProjects()->create(['keyword' => '인계동 파스타', 'learning_category_id' => $category->id]);
        $this->reference($keyword, 'own');
        $this->reference($category, 'learned-1');
        $before = AnalyzeKeywordJob::sourceHash($keyword);
        $this->reference($category, 'learned-2');

        // 카테고리에 참고 글을 더하면 키워드 분석도 새로 해야 한다
        $this->assertNotSame($before, AnalyzeKeywordJob::sourceHash($keyword));

        Http::fake(['*/keywords/analyze' => Http::response([
            'stats' => ['version' => 'stats-1', 'reference_count' => 3], 'insight' => null, 'insight_error' => 'x',
            'guide' => null, 'prompt_version' => 'keyword-analysis-v2', 'generations' => [],
        ])]);
        (new AnalyzeKeywordJob($keyword))->handle(app(AiWorkerClient::class), app(GenerationRecorder::class));

        Http::assertSent(fn ($r) => collect($r['features'])->pluck('layout')->sort()->values()->all() === ['learned-1', 'learned-2', 'own']
            && $r['category'] === null);
    }

    public function test_saved_titles_are_sent_so_old_features_get_title_shapes(): void
    {
        $category = $this->category('일상');
        $this->reference($category, 'old')->update(['title' => '[일상] 연휴때 내가 먹은 것들']);
        $fresh = $this->reference($category, 'new');
        $fresh->update(['title' => '이미 모양이 있는 글']);
        $fresh->features->update(['features_json' => [...$fresh->features->features_json, 'title' => ['shape' => '[{명사}] {키워드}']]]);
        Http::fake(['*/keywords/analyze' => Http::response(['stats' => null, 'insight' => null, 'insight_error' => 'x', 'guide' => null, 'prompt_version' => 'v', 'generations' => []])]);

        (new AnalyzeKeywordJob($category))->handle(app(AiWorkerClient::class), app(GenerationRecorder::class));

        // 모양이 없는 예전 특징에만 저장해 둔 제목을 붙인다(본문은 보내지 않는다)
        Http::assertSent(fn ($r) => collect($r['features'])->pluck('title_source', 'layout')->all() === ['old' => '[일상] 연휴때 내가 먹은 것들', 'new' => null]);
    }

    public function test_keyword_hashtags_include_the_learning_categorys_hashtags(): void
    {
        $category = $this->category();
        $category->forceFill(['hashtags_json' => ['맛집추천', '인계동파스타']])->save();
        $keyword = $this->user->keywordProjects()->create(['keyword' => '인계동 파스타', 'learning_category_id' => $category->id]);
        $keyword->analyses()->create(['analyzer_version' => 'x', 'guide_json' => ['hashtags' => [['tag' => '인계동파스타'], ['tag' => '수원맛집']]]]);

        $this->assertSame(['인계동파스타', '수원맛집', '맛집추천'], $keyword->fresh()->hashtags());

        $keyword->forceFill(['hashtags_json' => ['내가고친태그']])->save();
        $this->assertSame(['내가고친태그'], $keyword->fresh()->hashtags());
    }

    public function test_learning_progress_reports_step_time_and_reading(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $category = $this->category();
        $this->reference($category, 'a');
        $category->references()->create(['source_type' => 'user_url', 'source_url' => 'https://ex.com/p', 'usage_permission' => 'user_provided', 'parse_status' => 'pending']);

        $this->actingAs($this->user)->postJson("/api/projects/{$category->id}/analyze")
            ->assertStatus(202)
            ->assertJsonPath('progress.step', 'queued')
            ->assertJsonPath('progress.references', ['total' => 2, 'parsed' => 1, 'pending' => 1]);
        $this->assertNotNull($category->fresh()->analysis_started_at);

        Http::fake(['*/keywords/analyze' => function () use ($category) {
            // 워커를 부르는 동안에는 "AI 정리 중"
            $this->assertSame('ai', $category->fresh()->analysis_step);

            return Http::response(['stats' => null, 'insight' => null, 'insight_error' => 'x', 'guide' => null, 'prompt_version' => 'v', 'generations' => []]);
        }]);
        (new AnalyzeKeywordJob($category->fresh()))->handle(app(AiWorkerClient::class), app(GenerationRecorder::class));

        $this->actingAs($this->user)->getJson("/api/projects/{$category->id}/analysis")
            ->assertJsonPath('status', 'analyzed')
            ->assertJsonPath('progress.step', null);
    }
}
