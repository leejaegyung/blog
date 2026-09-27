<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\ProjectStatus;
use App\Models\Generation;
use App\Models\KeywordProject;
use App\Models\Post;
use App\Services\AiWorker\GenerationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_trace_id_is_echoed_or_generated_and_forwarded_to_worker(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'ok'])]);

        $this->getJson('/api/health', ['X-Request-Id' => 'trace-12345678'])->assertHeader('X-Request-Id', 'trace-12345678');
        Http::assertSent(fn ($r) => $r->hasHeader('X-Request-Id', 'trace-12345678'));

        $generated = $this->getJson('/api/health', ['X-Request-Id' => 'bad id!'])->headers->get('X-Request-Id');
        $this->assertTrue(Str::isUuid($generated));
    }

    public function test_llm_calls_are_logged_without_user_content(): void
    {
        Log::spy();
        $post = Post::factory()->create();

        app(GenerationRecorder::class)->record([[
            'provider' => 'anthropic', 'model' => 'claude-opus-5', 'status' => 'failed', 'latency_ms' => 900,
            'error_kind' => 'billing', 'error_message' => '사용자 사실: 비밀 메모',
        ]], purpose: 'draft', promptVersion: 'blog-draft-v2', post: $post);

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context) use ($post) {
            return $message === 'llm_call' && $context['purpose'] === 'draft' && $context['error_kind'] === 'billing'
                && $context['post_id'] === $post->id && ! str_contains(json_encode($context, JSON_UNESCAPED_UNICODE), '비밀 메모');
        })->once();
    }

    public function test_recover_stuck_work_only_touches_old_items(): void
    {
        $old = Post::factory()->create();
        $old->forceFill(['status' => PostStatus::Generating])->save();
        $fresh = Post::factory()->create();
        $fresh->forceFill(['status' => PostStatus::Planning])->save();
        $project = KeywordProject::factory()->create();
        $project->forceFill(['status' => ProjectStatus::Analyzing])->save();
        $image = $old->images()->create(['storage_key' => 'a.jpg', 'vision_status' => 'pending']);
        DB::table('posts')->where('id', $old->id)->update(['updated_at' => now()->subHour()]);
        DB::table('keyword_projects')->where('id', $project->id)->update(['updated_at' => now()->subHour()]);
        DB::table('post_images')->where('id', $image->id)->update(['updated_at' => now()->subHour()]);

        $this->artisan('app:recover-stuck')->expectsOutputToContain('drafts=1')->assertSuccessful();

        $this->assertSame(PostStatus::Failed, $old->fresh()->status);
        $this->assertStringContainsString('너무 오래', $old->fresh()->draft_error);
        $this->assertSame(PostStatus::Planning, $fresh->fresh()->status);
        $this->assertSame(ProjectStatus::Failed, $project->fresh()->status);
        $this->assertSame('failed', $image->fresh()->vision_status);
    }

    public function test_usage_aggregates_tokens_and_failures_for_own_data(): void
    {
        $post = Post::factory()->create();
        $post->forceFill(['content_original_json' => ['blocks' => []]])->save();
        $user = $post->user;
        $make = fn (array $attrs) => Generation::create($attrs + ['provider' => 'anthropic', 'model' => 'claude-opus-5', 'latency_ms' => 1000]);
        $make(['post_id' => $post->id, 'purpose' => 'draft', 'status' => 'success', 'input_tokens' => 1000, 'output_tokens' => 3000, 'latency_ms' => 50000]);
        $make(['post_id' => $post->id, 'purpose' => 'draft', 'status' => 'failed']);
        $make(['keyword_project_id' => $post->keyword_project_id, 'purpose' => 'keyword_analysis', 'status' => 'success', 'input_tokens' => 500, 'output_tokens' => 200]);
        $make(['post_id' => Post::factory()->create()->id, 'purpose' => 'draft', 'status' => 'success', 'input_tokens' => 99999]);

        $data = $this->actingAs($user)->getJson('/api/admin/usage?days=7')->assertOk()->json('data');

        $this->assertSame(3, $data['kpi']['llm_calls']);
        $this->assertSame(4700, $data['kpi']['tokens']);
        $this->assertEqualsWithDelta(0.333, $data['kpi']['llm_failure_rate'], 0.001);
        $this->assertSame([1, 1, 50000], [$data['kpi']['posts'], $data['kpi']['drafted'], $data['kpi']['avg_draft_latency_ms']]);
        $draft = collect($data['by_group'])->firstWhere('purpose', 'draft');
        $this->assertSame([2, 1], [(int) $draft['calls'], (int) $draft['success']]);
        $this->assertCount(1, $data['daily']);
    }

    public function test_failed_jobs_list_retry_and_forget(): void
    {
        $user = Post::factory()->create()->user;
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\GenerateDraftJob', 'job' => 'x', 'data' => []]),
            'exception' => "RuntimeException: boom\n#0 stack with 사용자 내용",
            'failed_at' => now(),
        ]);

        $this->actingAs($user)->getJson('/api/admin/failed-jobs')
            ->assertJsonPath('data.0.job', 'App\\Jobs\\GenerateDraftJob')
            ->assertJsonPath('data.0.error', 'RuntimeException: boom');

        $this->actingAs($user)->postJson("/api/admin/failed-jobs/{$uuid}/retry")->assertOk();
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertSame(1, DB::table('jobs')->count());

        $this->actingAs($user)->deleteJson("/api/admin/failed-jobs/{$uuid}")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/failed-jobs')->assertUnauthorized();
    }

    public function test_jobs_without_request_get_a_trace_id(): void
    {
        Context::flush();
        event(new \Illuminate\Queue\Events\JobProcessing('database', new class extends \Illuminate\Queue\Jobs\Job implements \Illuminate\Contracts\Queue\Job
        {
            public function getJobId()
            {
                return '1';
            }

            public function getRawBody()
            {
                return json_encode(['displayName' => 'App\\Jobs\\AnalyzeKeywordJob', 'job' => 'x', 'data' => []]);
            }

            public function attempts()
            {
                return 1;
            }
        }));

        $this->assertTrue(Str::isUuid(Context::get('trace_id')));
        $this->assertSame('App\\Jobs\\AnalyzeKeywordJob', Context::get('job'));
    }
}
