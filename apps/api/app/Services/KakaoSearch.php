<?php

namespace App\Services;

use App\Http\Controllers\Api\BlogSettingsController;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 카카오(다음) 블로그 검색 API로 검색어의 상위 티스토리 글 주소를 찾는다.
 * 공식 검색 API라 목록 페이지를 긁지 않는다. 찾은 글 본문은 워커가 robots.txt를 지키며 한 편씩 읽는다.
 */
class KakaoSearch
{
    public const ENDPOINT = 'https://dapi.kakao.com/v2/search/blog';

    /** 카카오 API 한 번에 최대 50개. 티스토리만 추리므로 넉넉히 받는다 */
    private const PAGE_SIZE = 50;

    /**
     * @return array{posts: list<array{url: string, title: string, blogname: string, datetime: ?string}>, error: ?string}
     */
    public function tistoryPosts(string $query, int $limit): array
    {
        $key = BlogSettingsController::kakaoKey();
        if (! $key) {
            return ['posts' => [], 'error' => '카카오 REST API 키가 없어요. 관리 › 블로그 연결에서 넣어 주세요.'];
        }

        $posts = [];
        // 다음 블로그 검색 상위는 대부분 네이버 글이라(예: "수원 맛집" 상위 100개 중 티스토리 1개),
        // 먼저 검색어 그대로의 상위 티스토리 글을 담고, 모자라면 "검색어 티스토리"로 찾은 상위 글로 채운다
        $queries = preg_match('/티스토리|tistory/iu', $query) ? [$query] : [$query, "{$query} 티스토리"];
        foreach ($queries as $q) {
            // 검색어마다 상위(정확도순) 2페이지까지만 본다. 그 밖은 "상위 글"이 아니다
            for ($page = 1; $page <= 2 && count($posts) < $limit; $page++) {
                try {
                    $response = Http::withHeaders(['Authorization' => "KakaoAK {$key}"])
                        ->acceptJson()
                        ->timeout(10)
                        ->get(self::ENDPOINT, ['query' => $q, 'sort' => 'accuracy', 'size' => self::PAGE_SIZE, 'page' => $page]);
                } catch (ConnectionException) {
                    return ['posts' => $posts, 'error' => '카카오 검색에 연결하지 못했어요. 잠시 뒤 다시 해 주세요.'];
                }
                if ($response->status() === 401 || $response->status() === 403) {
                    return ['posts' => [], 'error' => '카카오 REST API 키를 확인해 주세요(앱의 REST API 키인지, 다음 검색이 켜져 있는지).'];
                }
                if ($response->failed()) {
                    return ['posts' => $posts, 'error' => "카카오 검색이 실패했어요 ({$response->status()})."];
                }

                foreach ($response->json('documents') ?? [] as $doc) {
                    $url = self::canonical((string) ($doc['url'] ?? ''));
                    if ($url === null || isset($posts[$url])) {
                        continue;
                    }
                    $posts[$url] = [
                        'url' => $url,
                        'title' => self::plain((string) ($doc['title'] ?? '')),
                        'blogname' => self::plain((string) ($doc['blogname'] ?? '')),
                        'datetime' => $doc['datetime'] ?? null,
                    ];
                    if (count($posts) >= $limit) {
                        break;
                    }
                }
                if ($response->json('meta.is_end')) {
                    break;
                }
            }
        }

        return ['posts' => array_values($posts), 'error' => null];
    }

    /** 티스토리 글 주소만 남긴다(*.tistory.com의 글 페이지. 관리·검색·방명록 같은 주소는 뺀다) */
    public static function canonical(string $url): ?string
    {
        $parts = parse_url(trim($url));
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '/';
        if (! str_ends_with($host, '.tistory.com')) {
            return null;
        }
        if ($path === '/' || preg_match('#^/(manage|admin|search|guestbook|tag|category|m/)#', $path)) {
            return null;
        }

        return "https://{$host}".rtrim($path, '/');
    }

    /** 검색 결과 제목에 붙는 <b> 강조와 HTML 엔티티를 없앤다 */
    private static function plain(string $text): string
    {
        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5));
    }
}
