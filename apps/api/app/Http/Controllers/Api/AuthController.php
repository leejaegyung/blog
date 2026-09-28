<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): UserResource
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        // 아이디(admin) 또는 이메일
        $field = str_contains($data['login'], '@') ? 'email' : 'username';
        $credentials = [$field => $data['login'], 'password' => $data['password']];

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'login' => '아이디 또는 비밀번호가 올바르지 않습니다.',
            ]);
        }

        $request->session()->regenerate();

        return new UserResource($request->user());
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return (new UserResource($request->user()))->additional([
            'meta' => [
                'auto_login' => (bool) config('auth.auto_login.enabled'),
                // 6단계 "네이버 글쓰기 열기"가 쓰는 내 블로그 아이디
                'naver_blog_id' => NaverSettingsController::blogId(),
            ],
        ]);
    }
}
