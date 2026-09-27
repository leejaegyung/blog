<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_reports_degraded_when_ai_worker_is_down(): void
    {
        Http::fake(['*/health' => Http::response(status: 500)]);

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.ai_worker', false);
    }

    public function test_reports_ok_when_ai_worker_is_up(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'ok'])]);

        // 테스트 DB는 :memory: 라 WAL이 아니므로 database 체크는 검증 대상에서 제외한다.
        $this->getJson('/api/health')->assertJsonPath('checks.ai_worker', true);
    }
}
