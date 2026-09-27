<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 1인용·로컬 전용 자동 로그인. AUTH_AUTO_LOGIN=true면 로그인하지 않은 브라우저 요청을 지정한 계정으로 로그인시킨다.
 * 반드시 포트를 127.0.0.1에만 열어 둔 상태에서 쓴다(docker-compose.yml). 서버에 올릴 때는 끈다.
 */
class AutoLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        // 세션은 SPA(stateful) 요청에만 있다. curl 같은 요청은 자동 로그인하지 않는다
        if (config('auth.auto_login.enabled') && $request->hasSession() && Auth::guard('web')->guest()) {
            $user = User::where('username', config('auth.auto_login.username'))->first();
            if ($user) {
                Auth::guard('web')->login($user);
            }
        }

        return $next($request);
    }
}
