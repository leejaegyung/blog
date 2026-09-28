<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\LlmSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 관리 화면의 AI 연결. 글쓰기는 이 Mac의 구독(claude_code·codex)으로만 한다.
 * [API 연결 꺼 둠 2026-09-28] API 키 관리(Anthropic·OpenAI) 테스트는 기능과 함께 주석 처리한 뒤 지웠다.
 * 다시 켜면 git 기록(커밋 "API 연결 꺼 둠" 이전)의 AdminLlmTest를 되살린다.
 */
class AdminLlmTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        config(['services.llm.route' => 'claude_code:opus,codex:gpt-6-astra', 'services.llm.claude_bridge_token' => null]);
        Http::fake(['*/llm/models' => Http::response([
            'claude_code' => [['id' => 'opus', 'label' => 'Opus', 'description' => '최고 품질']],
            'codex' => [['id' => 'gpt-6-astra', 'label' => 'GPT-6-Astra', 'description' => ''], ['id' => 'gpt-6-sol', 'label' => 'GPT-6-Sol', 'description' => '']],
            'error' => null,
        ])]);
    }

    public function test_shows_only_subscription_providers_with_live_models(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/admin/llm')->assertOk();

        $response->assertJsonCount(2, 'data.providers')
            ->assertJsonPath('data.providers.0.provider', 'claude_code')
            ->assertJsonPath('data.providers.0.key_prefix', null)
            ->assertJsonPath('data.providers.0.source', 'none')
            ->assertJsonPath('data.providers.1.provider', 'codex')
            ->assertJsonPath('data.providers.1.models.1.id', 'gpt-6-sol')
            ->assertJsonPath('data.route.0', ['provider' => 'claude_code', 'model' => 'opus'])
            ->assertJsonPath('data.route_source', 'env');

        config(['services.llm.claude_bridge_token' => 'tok']);
        $this->actingAs($this->user)->getJson('/api/admin/llm')->assertJsonPath('data.providers.0.source', 'bridge');
    }

    public function test_falls_back_to_default_models_when_bridge_cannot_list_them(): void
    {
        Http::swap(new HttpFactory);  // setUp의 가짜 응답을 버리고 연결기가 꺼진 경우로 바꾼다
        Http::fake(['*/llm/models' => Http::response(['claude_code' => [], 'codex' => [], 'error' => '연결기 꺼짐'])]);

        $this->actingAs($this->user)->getJson('/api/admin/llm')
            ->assertJsonPath('data.providers.0.models.0.id', 'opus')
            ->assertJsonPath('data.providers.1.models.0.id', 'gpt-6-astra');
        $this->assertNull(Cache::get('llm.subscription_models')); // 실패한 목록은 캐시하지 않는다
    }

    public function test_api_key_management_is_turned_off(): void
    {
        $this->actingAs($this->user)->putJson('/api/admin/llm/keys/anthropic', ['api_key' => str_repeat('x', 30)])->assertNotFound();
        $this->actingAs($this->user)->deleteJson('/api/admin/llm/keys/openai')->assertNotFound();
    }

    public function test_route_update_accepts_only_subscriptions_dedupes_and_resets(): void
    {
        $this->actingAs($this->user)->putJson('/api/admin/llm/route', ['route' => [
            ['provider' => 'codex', 'model' => 'gpt-6-sol'],
            ['provider' => 'claude_code', 'model' => 'sonnet'],
            ['provider' => 'codex', 'model' => 'gpt-6-sol'],
        ]])->assertOk()->assertJsonCount(2, 'data.route')->assertJsonPath('data.route_source', 'admin');
        $this->assertSame(['codex:gpt-6-sol', 'claude_code:sonnet'], app(LlmSettings::class)->route());

        $this->actingAs($this->user)->putJson('/api/admin/llm/route', ['route' => [['provider' => 'openai', 'model' => 'gpt-5.5']]])
            ->assertJsonValidationErrors('route.0.provider');
        $this->actingAs($this->user)->putJson('/api/admin/llm/route', ['route' => [['provider' => 'codex', 'model' => 'GPT 5!']]])
            ->assertJsonValidationErrors('route.0.model');

        $this->actingAs($this->user)->putJson('/api/admin/llm/route', ['reset' => true])->assertJsonPath('data.route_source', 'env');
    }

    public function test_worker_calls_carry_route_but_no_api_keys(): void
    {
        config(['services.llm.anthropic_key' => 'sk-ant-from-env-0000000000000000-envv']);
        Http::fake(['*/health' => Http::response(['status' => 'ok']), '*/llm/ping' => Http::response(['text' => 'pong', 'generations' => []])]);
        app(LlmSettings::class)->setRoute(['codex:gpt-6-astra']);

        app(AiWorkerClient::class)->healthy();
        app(AiWorkerClient::class)->pingLlm();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/health') && ! $request->hasHeader('X-LLM-Config'));
        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/llm/ping')) {
                return false;
            }

            return json_decode(base64_decode($request->header('X-LLM-Config')[0]), true) === ['route' => 'codex:gpt-6-astra'];
        });
    }

    public function test_connection_test_reports_provider_message_and_account_without_keys(): void
    {
        Http::fake(['*/llm/ping' => Http::response(['detail' => ['message' => 'x', 'generations' => [
            ['provider' => 'codex', 'model' => 'gpt-6-astra', 'status' => 'failed', 'latency_ms' => 600,
                'error_kind' => 'auth', 'account' => 'user-9ioz',
                'error_message' => 'unauthorized (401) sk-proj-SECRETSECRET'],
        ]]], 503)]);

        $response = $this->actingAs($this->user)->postJson('/api/admin/llm/test', ['target' => 'codex:gpt-6-astra'])
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.attempts.0.error_kind', 'auth')
            ->assertJsonPath('data.attempts.0.account', 'user-9ioz');

        $this->assertStringContainsString('unauthorized (401)', $response->json('data.attempts.0.error_message'));
        $this->assertStringNotContainsString('SECRETSECRET', $response->getContent());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/llm/ping') && $r['targets'] === ['codex:gpt-6-astra']);
        $this->actingAs($this->user)->postJson('/api/admin/llm/test', ['target' => 'anthropic:claude-opus-5'])->assertJsonValidationErrors('target');
    }

    public function test_requires_login(): void
    {
        $this->getJson('/api/admin/llm')->assertUnauthorized();
        $this->putJson('/api/admin/llm/route', ['reset' => true])->assertUnauthorized();
    }
}
