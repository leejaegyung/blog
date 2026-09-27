<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\LlmSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminLlmTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $key = 'sk-ant-api03-ABCDEFGHIJKLMNOPQRSTUVWXYZ-1234567890-wxyz';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        config(['services.llm.anthropic_key' => 'sk-ant-from-env-0000000000000000-envv', 'services.llm.openai_key' => null,
            'services.llm.route' => 'anthropic:claude-opus-5,openai:gpt-5.5']);
    }

    public function test_show_reports_sources_and_never_exposes_keys(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/admin/llm')->assertOk();

        $response->assertJsonPath('data.providers.0.source', 'env')
            ->assertJsonPath('data.providers.0.masked_key', 'sk-ant-…envv')
            ->assertJsonPath('data.providers.1.source', 'none')
            ->assertJsonPath('data.route.0', ['provider' => 'anthropic', 'model' => 'claude-opus-5'])
            ->assertJsonPath('data.route_source', 'env');
        $this->assertStringNotContainsString('from-env-0000', $response->getContent());
    }

    public function test_admin_key_is_encrypted_overrides_env_and_can_be_removed(): void
    {
        $this->actingAs($this->user)->putJson('/api/admin/llm/keys/anthropic', ['api_key' => "  {$this->key}  "])
            ->assertOk()
            ->assertJsonPath('data.providers.0.source', 'admin')
            ->assertJsonPath('data.providers.0.masked_key', 'sk-ant-…wxyz');

        $stored = AppSetting::find('llm.anthropic.api_key')->value;
        $this->assertStringNotContainsString('ABCDEFGHIJ', $stored);
        $this->assertSame($this->key, app(LlmSettings::class)->apiKey('anthropic'));

        $this->actingAs($this->user)->deleteJson('/api/admin/llm/keys/anthropic')
            ->assertJsonPath('data.providers.0.source', 'env');
    }

    public function test_key_validation(): void
    {
        $this->actingAs($this->user)->putJson('/api/admin/llm/keys/openai', ['api_key' => 'sk-ant-wrong but long enough 123'])
            ->assertJsonValidationErrors('api_key');
        $this->actingAs($this->user)->putJson('/api/admin/llm/keys/anthropic', ['api_key' => 'sk-openai-000000000000000000'])
            ->assertJsonValidationErrors(['api_key' => 'sk-ant-로 시작하는 키를 공백 없이 넣어 주세요.']);
        $this->actingAs($this->user)->putJson('/api/admin/llm/keys/gemini', ['api_key' => str_repeat('x', 30)])->assertNotFound();
    }

    public function test_route_update_dedupes_validates_and_resets(): void
    {
        $this->actingAs($this->user)->putJson('/api/admin/llm/route', ['route' => [
            ['provider' => 'openai', 'model' => 'gpt-5.4-mini'],
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'],
            ['provider' => 'openai', 'model' => 'gpt-5.4-mini'],
        ]])->assertOk()->assertJsonCount(2, 'data.route')->assertJsonPath('data.route_source', 'admin');
        $this->assertSame(['openai:gpt-5.4-mini', 'anthropic:claude-sonnet-5'], app(LlmSettings::class)->route());

        $this->actingAs($this->user)->putJson('/api/admin/llm/route', ['route' => [['provider' => 'openai', 'model' => 'GPT 5!']]])
            ->assertJsonValidationErrors('route.0.model');

        $this->actingAs($this->user)->putJson('/api/admin/llm/route', ['reset' => true])->assertJsonPath('data.route_source', 'env');
    }

    public function test_worker_calls_carry_current_settings_in_header(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'ok']), '*/llm/ping' => Http::response(['text' => 'pong', 'generations' => []])]);
        app(LlmSettings::class)->setApiKey('openai', 'sk-proj-admin-key-000000000000');
        app(LlmSettings::class)->setRoute(['openai:gpt-5.5']);

        app(AiWorkerClient::class)->healthy();
        app(AiWorkerClient::class)->pingLlm();

        // LLM을 쓰지 않는 호출에는 키를 보내지 않는다
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/health') && ! $request->hasHeader('X-LLM-Config'));
        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/llm/ping')) {
                return false;
            }
            $config = json_decode(base64_decode($request->header('X-LLM-Config')[0]), true);

            return $config === [
                'anthropic_api_key' => 'sk-ant-from-env-0000000000000000-envv',
                'openai_api_key' => 'sk-proj-admin-key-000000000000',
                'route' => 'openai:gpt-5.5',
            ];
        });
    }

    public function test_connection_test_reports_attempts_without_error_text(): void
    {
        Http::fake(['*/llm/ping' => Http::response(['detail' => ['message' => 'x', 'generations' => [
            ['provider' => 'anthropic', 'model' => 'claude-opus-5', 'status' => 'failed', 'latency_ms' => 600,
                'error_kind' => 'billing', 'error_message' => 'credit balance too low for org xyz'],
        ]]], 503)]);

        $response = $this->actingAs($this->user)->postJson('/api/admin/llm/test', ['target' => 'anthropic:claude-opus-5'])
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.attempts.0.error_kind', 'billing');

        $this->assertStringNotContainsString('org xyz', $response->getContent());
        Http::assertSent(fn ($r) => $r['targets'] === ['anthropic:claude-opus-5']);
        $this->actingAs($this->user)->postJson('/api/admin/llm/test', ['target' => 'evil'])->assertJsonValidationErrors('target');
    }

    public function test_requires_login(): void
    {
        $this->getJson('/api/admin/llm')->assertUnauthorized();
        $this->putJson('/api/admin/llm/keys/anthropic', ['api_key' => $this->key])->assertUnauthorized();
    }
}
