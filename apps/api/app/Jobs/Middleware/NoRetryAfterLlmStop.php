<?php

namespace App\Jobs\Middleware;

use App\Services\AiWorker\LlmStopException;
use Closure;

/**
 * AI 호출이 시간 초과로 끊겼거나 시간당 한도를 넘었으면 자동 재시도하지 않고 바로 실패로 끝낸다.
 * (재시도하면 같은 요청을 다시 보내 구독 사용량을 두 번 쓴다)
 */
class NoRetryAfterLlmStop
{
    public function handle(object $job, Closure $next): void
    {
        try {
            $next($job);
        } catch (LlmStopException $e) {
            $job->fail($e);
        }
    }
}
