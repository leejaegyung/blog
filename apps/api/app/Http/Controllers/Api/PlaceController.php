<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\KakaoLocal;
use App\Support\MapUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** 글쓰기 3단계 "장소 연결": 지도 링크나 가게 이름으로 장소 후보를 찾는다(저장은 글 저장 때 사용자가 고른 것만) */
class PlaceController extends Controller
{
    public function lookup(Request $request, KakaoLocal $local): JsonResponse
    {
        $data = $request->validate(['input' => ['required', 'string', 'max:2000']], ['input.required' => '지도 링크나 가게 이름을 넣어 주세요.']);
        $parsed = MapUrl::parse($data['input']);

        if (! $parsed['name'] && $parsed['lat'] === null) {
            throw ValidationException::withMessages(['input' => $parsed['short']
                ? '단축 링크(naver.me·maps.app.goo.gl)는 열어 보지 않아서 어느 가게인지 알 수 없어요. 링크 뒤에 가게 이름을 한 칸 띄우고 적어 주세요.'
                : '링크에서 가게 이름을 찾지 못했어요. 링크 뒤에 가게 이름을 한 칸 띄우고 적어 주세요.']);
        }

        $found = $local->search($parsed['name'], $parsed['lat'], $parsed['lng'], $parsed['address']);
        if ($found['error'] !== null) {
            throw ValidationException::withMessages(['input' => $found['error']]);
        }

        return response()->json(['data' => $found['places'], 'parsed' => $parsed]);
    }
}
