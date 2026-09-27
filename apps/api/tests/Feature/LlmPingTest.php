<?php

namespace Tests\Feature;

use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LlmPingTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_every_attempt_including_fallback(): void
    {
        Http::fake(['*/llm/ping' => Http::response([
            'text' => 'pong',
            'generations' => [
                ['provider' => 'anthropic', 'model' => 'claude-opus-5', 'status' => 'failed', 'latency_ms' => 900,
                    'error_kind' => 'unavailable', 'error_message' => 'overloaded'],
                ['provider' => 'openai', 'model' => 'gpt-5.5', 'status' => 'success', 'latency_ms' => 1200,
                    'input_tokens' => 20, 'output_tokens' => 4],
            ],
        ])]);

        $this->artisan('app:llm-ping')->expectsOutputToContain('응답: pong')->assertSuccessful();

        $this->assertSame(2, Generation::count());
        $failed = Generation::where('status', 'failed')->sole();
        $this->assertSame('unavailable: overloaded', $failed->error_message);
        $ok = Generation::where('status', 'success')->sole();
        $this->assertSame(['ping', 'openai', 20, 4, null], [$ok->purpose, $ok->provider, $ok->input_tokens, $ok->output_tokens, $ok->cost]);
    }

    public function test_passes_targets_and_fails_when_all_targets_fail(): void
    {
        Http::fake(['*/llm/ping' => Http::response(['detail' => [
            'message' => 'all failed',
            'generations' => [['provider' => 'openai', 'model' => 'gpt-5.5', 'status' => 'failed', 'latency_ms' => 0, 'error_kind' => 'not_configured']],
        ]], 503)]);

        $this->artisan('app:llm-ping', ['--target' => ['openai:gpt-5.5']])->assertFailed();

        Http::assertSent(fn ($request) => $request['targets'] === ['openai:gpt-5.5']);
        $this->assertSame('not_configured:', Generation::sole()->error_message);
    }

    public function test_worker_down(): void
    {
        Http::fake(['*/llm/ping' => Http::response('boom', 500)]);

        $this->artisan('app:llm-ping')->assertFailed();
        $this->assertDatabaseEmpty('generations');
    }
}
