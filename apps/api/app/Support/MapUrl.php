<?php

namespace App\Support;

/**
 * 붙여넣은 지도 링크 글자에서 장소 이름·좌표만 읽는다. 링크를 열지 않는다
 * (네이버 지도는 서버가 가져오지 않는 네이버 도메인이고, 구글 지도는 자동으로 긁지 않는다).
 */
final class MapUrl
{
    /**
     * @return array{source: string, url: ?string, name: ?string, address: ?string, lat: ?float, lng: ?float, short: bool}
     */
    public static function parse(string $input): array
    {
        $input = trim($input);
        $url = preg_match('#https?://\S+#u', $input, $m) ? rtrim($m[0], '.,)') : null;
        // 링크 말고 함께 적은 글자는 가게 이름으로 쓴다(예: "https://naver.me/abc 파스타인계").
        // 지도 앱 "공유 → 복사"는 여러 줄로 온다: [네이버 지도] / 가게 이름 / 주소 / 링크 → 첫 줄을 이름, 다음 줄을 주소로
        $lines = array_values(array_filter(
            array_map(fn ($line) => trim(preg_replace('/\s+/u', ' ', $line)), preg_split('/\R/u', $url ? str_replace($m[0], "\n", $input) : $input)),
            fn ($line) => $line !== '' && ! preg_match('/^\[[^\]]*\]$/u', $line),
        ));
        $lines = array_map(fn ($line) => trim(preg_replace('/^\[[^\]]*\]\s*/u', '', $line)), $lines);
        $hint = $lines[0] ?? '';
        $address = isset($lines[1]) && preg_match('/(시|도|구|군|동|읍|면|로|길)\s*\d|\d+(-\d+)?$/u', $lines[1]) ? $lines[1] : null;
        $result = ['source' => 'text', 'url' => $url, 'name' => $hint !== '' ? $hint : null, 'address' => $address, 'lat' => null, 'lng' => null, 'short' => false];
        if (! $url) {
            return $result;
        }

        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $path = rawurldecode($parts['path'] ?? '');
        parse_str($parts['query'] ?? '', $query);
        $name = null;
        [$lat, $lng] = [null, null];

        if (str_ends_with($host, 'naver.me') || str_ends_with($host, 'maps.app.goo.gl') || $host === 'goo.gl' || $host === 'kko.to') {
            // 단축 링크는 열어야만 알 수 있어 이름을 함께 받는다
            $result['short'] = true;
        }
        if (str_contains($host, 'naver')) {
            $result['source'] = 'naver';
            if (preg_match('#/search/([^/]+)#u', $path, $p)) {
                $name = $p[1];
            }
            if (isset($query['lat'], $query['lng'])) {
                [$lat, $lng] = [(float) $query['lat'], (float) $query['lng']];
            } elseif (isset($query['c']) && preg_match('/^(-?[\d.]+),(-?[\d.]+)/', (string) $query['c'], $c) && abs((float) $c[1]) > 1000) {
                // 예전 네이버 지도: c=경도·위도를 웹 메르카토르(미터)로
                [$lat, $lng] = self::fromMercator((float) $c[1], (float) $c[2]);
            }
        } elseif (str_contains($host, 'google') || str_contains($host, 'goo.gl')) {
            $result['source'] = 'google';
            if (preg_match('#/maps/(?:place|search)/([^/]+)#u', $path, $p)) {
                $name = str_replace('+', ' ', $p[1]);
            }
            if (preg_match('#@(-?\d+\.\d+),(-?\d+\.\d+)#', $path, $p)) {
                [$lat, $lng] = [(float) $p[1], (float) $p[2]];
            } elseif (preg_match('#!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)#', $path, $p)) {
                [$lat, $lng] = [(float) $p[1], (float) $p[2]];
            }
            $q = (string) ($query['q'] ?? $query['query'] ?? '');
            if (preg_match('/^(-?\d+\.\d+),\s*(-?\d+\.\d+)$/', $q, $p)) {
                [$lat, $lng] = [(float) $p[1], (float) $p[2]];
            } elseif ($q !== '' && ! $name) {
                $name = $q;
            }
        } elseif (str_contains($host, 'kakao') || $host === 'kko.to') {
            $result['source'] = 'kakao';
            // map.kakao.com/link/map/이름,위도,경도
            if (preg_match('#/link/(?:map|to)/([^,]+),(-?\d+\.\d+),(-?\d+\.\d+)#u', $path, $p)) {
                [$name, $lat, $lng] = [$p[1], (float) $p[2], (float) $p[3]];
            }
            $name ??= isset($query['q']) ? (string) $query['q'] : null;
        } else {
            $result['source'] = 'other';
        }

        // 좌표가 한국 근처가 아니면 버린다(잘못 읽은 숫자)
        if ($lat !== null && ! ($lat > 32 && $lat < 39.5 && $lng > 124 && $lng < 132)) {
            [$lat, $lng] = [null, null];
        }

        return [...$result, 'name' => $result['name'] ?? (($name = trim((string) $name)) !== '' ? $name : null), 'lat' => $lat, 'lng' => $lng];
    }

    /** @return array{0: float, 1: float} [위도, 경도] */
    private static function fromMercator(float $x, float $y): array
    {
        $lng = $x / 6378137 * 180 / M_PI;
        $lat = (2 * atan(exp($y / 6378137)) - M_PI / 2) * 180 / M_PI;

        return [round($lat, 6), round($lng, 6)];
    }
}
