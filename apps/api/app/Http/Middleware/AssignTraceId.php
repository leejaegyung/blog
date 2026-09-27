<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * 요청마다 trace_id를 정한다(기획서 23장). Context에 넣으면 로그에 자동으로 붙고, 이 요청에서 보낸 큐 작업에도 이어진다.
 * AI Worker 호출에는 X-Request-Id 헤더로 전달한다.
 */
class AssignTraceId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header('X-Request-Id');
        $traceId = preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid();
        Context::add('trace_id', $traceId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $traceId);

        return $response;
    }
}
