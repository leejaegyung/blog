<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Vue SPA와 API가 NGINX 뒤 같은 origin이므로 Sanctum 쿠키 세션 인증을 쓴다.
        $middleware->statefulApi();
        $middleware->append(\App\Http\Middleware\AssignTraceId::class);
        // 세션 시작(statefulApi) 뒤에 붙이고, 인증 미들웨어보다 먼저 돌도록 우선순위에도 넣는다
        // (Laravel은 auth 계열 미들웨어를 우선순위 목록에 따라 앞으로 당긴다)
        $middleware->api(append: [\App\Http\Middleware\AutoLogin::class]);
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\AutoLogin::class,
        );
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
