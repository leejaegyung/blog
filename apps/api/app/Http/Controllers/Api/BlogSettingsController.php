<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\KakaoSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * 블로그 연결: 네이버 블로그 아이디, 티스토리 블로그 주소, 카카오 REST API 키(티스토리 상위 글 찾기).
 * 카카오 키는 암호화해 저장하고 화면에는 넣었는지만 알려 준다.
 */
class BlogSettingsController extends Controller
{
    public const TISTORY_KEY = 'tistory.host';

    public const KAKAO_KEY = 'kakao.rest_api_key';

    public static function tistoryHost(): ?string
    {
        return AppSetting::find(self::TISTORY_KEY)?->value ?: null;
    }

    public static function kakaoKey(): ?string
    {
        $value = AppSetting::find(self::KAKAO_KEY)?->value;
        if (! $value) {
            return config('services.kakao.rest_api_key') ?: null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{naver_blog_id: ?string, tistory_host: ?string, kakao_ready: bool} */
    public static function summary(): array
    {
        return [
            'naver_blog_id' => NaverSettingsController::blogId(),
            'tistory_host' => self::tistoryHost(),
            'kakao_ready' => self::kakaoKey() !== null,
        ];
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => self::summary()]);
    }

    /** 보낸 항목만 바꾼다. 빈 값은 지운다 */
    public function update(Request $request): JsonResponse
    {
        if ($request->has('tistory_host')) {
            // https://myblog.tistory.com/123 처럼 통째로 붙여넣어도 주소만, "myblog"만 넣으면 myblog.tistory.com
            $raw = strtolower(trim((string) $request->input('tistory_host')));
            $host = parse_url(str_contains($raw, '://') ? $raw : "https://{$raw}", PHP_URL_HOST) ?: '';
            if ($host !== '' && ! str_contains($host, '.')) {
                $host .= '.tistory.com';
            }
            $request->merge(['tistory_host' => $host === '' ? null : $host]);
        }
        $data = $request->validate([
            'tistory_host' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/'],
            'kakao_key' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9]+$/'],
        ], [
            'tistory_host.regex' => '티스토리 블로그 주소를 확인해 주세요(예: myblog.tistory.com).',
            'kakao_key.regex' => '카카오 REST API 키는 영문·숫자로만 되어 있어요.',
        ]);

        if (array_key_exists('tistory_host', $data)) {
            $this->put(self::TISTORY_KEY, $data['tistory_host']);
        }
        if (array_key_exists('kakao_key', $data)) {
            $this->put(self::KAKAO_KEY, $data['kakao_key'] === null ? null : Crypt::encryptString($data['kakao_key']));
        }

        return $this->show();
    }

    /** 넣은 카카오 키로 실제 검색이 되는지 확인한다(결과 글은 저장하지 않는다) */
    public function testKakao(KakaoSearch $kakao): JsonResponse
    {
        $result = $kakao->tistoryPosts('맛집', 5);

        return response()->json(['data' => ['ok' => $result['error'] === null, 'error' => $result['error'], 'found' => count($result['posts'])]]);
    }

    private function put(string $key, ?string $value): void
    {
        if ($value === null) {
            AppSetting::whereKey($key)->delete();
        } else {
            AppSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
