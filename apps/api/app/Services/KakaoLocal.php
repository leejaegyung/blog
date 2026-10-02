<?php

namespace App\Services;

use App\Http\Controllers\Api\BlogSettingsController;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** 카카오 로컬 API(공식)로 장소를 찾는다: 이름(+좌표 근처) 검색, 좌표 → 주소 */
class KakaoLocal
{
    public const KEYWORD = 'https://dapi.kakao.com/v2/local/search/keyword.json';

    public const COORD2ADDRESS = 'https://dapi.kakao.com/v2/local/geo/coord2address.json';

    /** 좌표가 있으면 이 반경(미터) 안에서 찾는다 */
    private const RADIUS = 3000;

    /**
     * @return array{places: list<array<string, mixed>>, error: ?string}
     */
    public function search(?string $name, ?float $lat, ?float $lng, ?string $address = null): array
    {
        $key = BlogSettingsController::kakaoKey();
        if (! $key) {
            return ['places' => [], 'error' => '카카오 REST API 키가 없어요. 관리 › 내 티스토리 블로그에서 넣어 주세요.'];
        }
        try {
            if ($name) {
                $params = ['query' => $name, 'size' => 5];
                if ($lat !== null) {
                    $params += ['x' => $lng, 'y' => $lat, 'radius' => self::RADIUS];
                }
                $response = $this->get($key, self::KEYWORD, $params);
                if ($error = $this->error($response)) {
                    return ['places' => [], 'error' => $error];
                }
                $places = array_map(fn ($doc) => self::place($doc), $response->json('documents') ?? []);
                // 좌표 근처에 없으면 이름만으로 다시 찾는다(링크 좌표가 지도 화면 가운데일 수 있다)
                if ($places === [] && $lat !== null) {
                    return $this->search($name, null, null, $address);
                }
                // 이름이 카카오에 다르게 올라 있으면 "주소 앞부분 + 이름"으로 한 번 더(예: 지점명 표기 차이)
                if ($places === [] && $address) {
                    $area = implode(' ', array_slice(preg_split('/\s+/u', $address), 0, 3));
                    $retry = $this->get($key, self::KEYWORD, ['query' => "{$area} ".mb_substr($name, 0, 20), 'size' => 5]);
                    $places = $retry->successful() ? array_map(fn ($doc) => self::place($doc), $retry->json('documents') ?? []) : [];
                }

                return ['places' => $places, 'error' => null];
            }
            if ($lat !== null) {
                $response = $this->get($key, self::COORD2ADDRESS, ['x' => $lng, 'y' => $lat]);
                if ($error = $this->error($response)) {
                    return ['places' => [], 'error' => $error];
                }
                $doc = $response->json('documents.0');

                return ['places' => $doc ? [[
                    'kakao_id' => null, 'name' => null, 'category' => null, 'phone' => null,
                    'address' => $doc['address']['address_name'] ?? null,
                    'road_address' => $doc['road_address']['address_name'] ?? null,
                    'lat' => $lat, 'lng' => $lng, 'kakao_url' => null, 'distance_m' => null,
                ]] : [], 'error' => null];
            }
        } catch (ConnectionException) {
            return ['places' => [], 'error' => '카카오 장소 검색에 연결하지 못했어요. 잠시 뒤 다시 해 주세요.'];
        }

        return ['places' => [], 'error' => null];
    }

    private function get(string $key, string $url, array $params): Response
    {
        return Http::withHeaders(['Authorization' => "KakaoAK {$key}"])->acceptJson()->timeout(10)->get($url, $params);
    }

    private function error(Response $response): ?string
    {
        if ($response->successful()) {
            return null;
        }
        if (str_contains((string) $response->json('message'), 'OPEN_MAP_AND_LOCAL')) {
            return '카카오 앱에서 카카오맵(로컬) 사용이 꺼져 있어요. developers.kakao.com › 내 애플리케이션 › 앱 › 제품 설정 › 카카오맵 › 사용 설정을 ON으로 바꿔 주세요.';
        }
        if (in_array($response->status(), [401, 403], true)) {
            return '카카오 REST API 키를 확인해 주세요.';
        }

        return "카카오 장소 검색이 실패했어요 ({$response->status()}).";
    }

    /** @param array<string, mixed> $doc */
    private static function place(array $doc): array
    {
        return [
            'kakao_id' => $doc['id'] ?? null,
            'name' => $doc['place_name'] ?? null,
            'category' => $doc['category_name'] ?? null,
            'phone' => ($doc['phone'] ?? '') ?: null,
            'address' => ($doc['address_name'] ?? '') ?: null,
            'road_address' => ($doc['road_address_name'] ?? '') ?: null,
            'lat' => isset($doc['y']) ? (float) $doc['y'] : null,
            'lng' => isset($doc['x']) ? (float) $doc['x'] : null,
            'kakao_url' => ($doc['place_url'] ?? '') ?: null,
            'distance_m' => isset($doc['distance']) && $doc['distance'] !== '' ? (int) $doc['distance'] : null,
        ];
    }
}
