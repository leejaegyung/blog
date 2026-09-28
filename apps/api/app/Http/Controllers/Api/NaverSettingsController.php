<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 내 네이버 블로그 아이디: 6단계에서 내 블로그 글쓰기(편집기)를 바로 연다 */
class NaverSettingsController extends Controller
{
    public const KEY = 'naver.blog_id';

    public static function blogId(): ?string
    {
        return AppSetting::find(self::KEY)?->value ?: null;
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => ['blog_id' => self::blogId()]]);
    }

    public function update(Request $request): JsonResponse
    {
        // 블로그 주소를 통째로 붙여넣어도 아이디만 뽑는다(blog.naver.com/{아이디}...)
        $raw = trim((string) $request->input('blog_id', ''));
        if (preg_match('#blog\.naver\.com/([A-Za-z0-9_\-]+)#', $raw, $m)) {
            $raw = $m[1];
        }
        $request->merge(['blog_id' => $raw === '' ? null : $raw]);
        $data = $request->validate(
            ['blog_id' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_\-]+$/']],
            ['blog_id.regex' => '네이버 블로그 아이디는 영문·숫자·_·- 만 써요.'],
        );

        if ($data['blog_id'] === null) {
            AppSetting::whereKey(self::KEY)->delete();
        } else {
            AppSetting::updateOrCreate(['key' => self::KEY], ['value' => $data['blog_id']]);
        }

        return $this->show();
    }
}
