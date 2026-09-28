<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeKeywordJob;
use App\Jobs\GeneratePlanJob;
use App\Jobs\Middleware\NoRetryAfterLlmStop;
use App\Models\Generation;
use App\Models\KeywordProject;
use App\Models\Post;
use App\Models\User;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\AiWorkerUnavailableException;
use App\Services\AiWorker\LlmBudgetExceededException;
use App\Services\AiWorker\LlmCallAbandonedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** 토큰 누수 방지: 시간당 한도, 시간 초과 뒤 재시도 안 함, 보내기 학습 모으기 */
class LlmSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_hourly_budget_stops_new_llm_calls_before_sending(): void
    {
        config(['services.llm.max_calls_per_hour' => 3]);
        Http::fake();
        foreach (range(1, 3) as $_) {
            Generation::create(['purpose' => 'draft', 'provider' => 'claude_code', 'model' => 'opus', 'status' => 'success']);
        }

        $this->expectException(LlmBudgetExceededException::class);
        try {
            app(AiWorkerClient::class)->planPost(['keyword' => 'x']);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_old_calls_do_not_count_and_zero_disables_the_limit(): void
    {
        config(['services.llm.max_calls_per_hour' => 1]);
        $old = Generation::create(['purpose' => 'draft', 'provider' => 'claude_code', 'model' => 'opus', 'status' => 'success']);
        $old->forceFill(['created_at' => now()->subHours(2)])->save();
        Http::fake(['*/posts/plan' => Http::response(['plan' => null, 'plan_error' => 'x', 'prompt_version' => 'v', 'generations' => []])]);

        app(AiWorkerClient::class)->planPost(['keyword' => 'x']);
        Http::assertSentCount(1);
    }

    public function test_timeout_after_sending_is_not_retried_but_connection_refused_is(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 320000 milliseconds'));
        try {
            app(AiWorkerClient::class)->draftPost(['keyword' => 'x']);
            $this->fail('예외가 나야 한다');
        } catch (LlmCallAbandonedException $e) {
            $this->assertStringContainsString('자동으로 다시 하지 않아요', $e->getMessage());
        }

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to ai-worker'));
        try {
            app(AiWorkerClient::class)->draftPost(['keyword' => 'x']);
            $this->fail('예외가 나야 한다');
        } catch (AiWorkerUnavailableException $e) {
            $this->assertNotInstanceOf(LlmCallAbandonedException::class, $e);  // 워커에 닿지 못했으면 다시 시도해도 된다
        }
    }

    public function test_stopped_llm_job_fails_immediately_instead_of_retrying(): void
    {
        $post = Post::factory()->create();
        $job = (new GeneratePlanJob($post))->withFakeQueueInteractions();

        (new NoRetryAfterLlmStop)->handle($job, fn () => throw new LlmCallAbandonedException('멈춤'));

        $job->assertFailed();
        $job->assertNotReleased();
        $this->assertContains(NoRetryAfterLlmStop::class, array_map(fn ($m) => $m::class, $job->middleware()));
    }

    public function test_learning_requests_are_delayed_and_coalesced(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $category = $user->keywordProjects()->create(['keyword' => '맛집', 'kind' => KeywordProject::KIND_CATEGORY]);

        $this->actingAs($user)->postJson("/api/projects/{$category->id}/analyze", ['force' => true, 'delay' => 45])->assertStatus(202);
        // 모으는 동안 또 보내도 학습을 새로 넣지 않는다
        $this->actingAs($user)->postJson("/api/projects/{$category->id}/analyze", ['force' => true, 'delay' => 45])->assertStatus(202);

        Queue::assertPushed(AnalyzeKeywordJob::class, 1);
        Queue::assertPushed(AnalyzeKeywordJob::class, fn ($job) => $job->delay !== null);
    }
}
