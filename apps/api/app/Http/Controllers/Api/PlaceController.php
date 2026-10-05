<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiWorker\AiWorkerClient;
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

    /**
     * 알려줄 내용 속 장소 이름(예: "카시오 도산점", "서울숲")을 찾아 카카오 로컬로 확인한다.
     * 이미 연결한 장소가 있으면 그 근처(20km)부터 찾는다. 이름이 겹치지 않는 결과는 버린다.
     */
    public function detect(Request $request, KakaoLocal $local, AiWorkerClient $worker): JsonResponse
    {
        $data = $request->validate([
            'texts' => ['required', 'array', 'max:40'],
            'texts.*' => ['string', 'max:2000'],
            'near.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'near.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'exclude' => ['sometimes', 'array', 'max:20'],
            'exclude.*' => ['string', 'max:30'],
        ]);
        $phrases = $worker->placeCandidates(array_filter($data['texts'], fn ($t) => trim($t) !== ''));
        $lat = isset($data['near']['lat']) ? (float) $data['near']['lat'] : null;
        $lng = isset($data['near']['lng']) ? (float) $data['near']['lng'] : null;
        $exclude = array_flip($data['exclude'] ?? []);

        $suggestions = [];
        foreach (array_slice($phrases, 0, self::DETECT_MAX) as $phrase) {
            $found = $local->search($phrase, $lat, $lng, radius: 20000, size: 3);
            if ($found['error'] !== null) {
                throw ValidationException::withMessages(['texts' => $found['error']]);
            }
            $place = collect($found['places'])->first(fn ($p) => $p['name'] && self::sameName($phrase, $p['name']));
            if ($place && ! isset($exclude[$place['kakao_id']]) && ! isset($suggestions[$place['kakao_id']])) {
                $suggestions[$place['kakao_id']] = ['query' => $phrase, ...$place];
            }
        }

        return response()->json(['data' => array_values($suggestions), 'candidates' => $phrases]);
    }

    public const DETECT_MAX = 6;

    /** 찾은 장소 이름이 적은 말과 실제로 겹치는지("서울숲 산책" ↔ "서울숲", "카시오 도산점" ↔ "카시오 스토어 도산") */
    private static function sameName(string $phrase, string $name): bool
    {
        $name = str_replace(' ', '', mb_strtolower($name));
        foreach (preg_split('/\s+/u', mb_strtolower($phrase)) as $word) {
            $word = preg_replace('/(점|에서|에|의)$/u', '', $word);
            if (mb_strlen($word) >= 2 && str_contains($name, $word)) {
                return true;
            }
        }

        return false;
    }
}
