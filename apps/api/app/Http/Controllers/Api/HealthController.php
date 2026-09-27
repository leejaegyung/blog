<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiWorker\AiWorkerClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(AiWorkerClient $worker): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::scalar('PRAGMA journal_mode') === 'wal'),
            'ai_worker' => $this->check(fn () => $worker->healthy()),
        ];

        $ok = ! in_array(false, $checks, true);

        return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks], $ok ? 200 : 503);
    }

    private function check(callable $probe): bool
    {
        try {
            return (bool) $probe();
        } catch (Throwable) {
            return false;
        }
    }
}
