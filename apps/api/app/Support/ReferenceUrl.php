<?php

namespace App\Support;

class ReferenceUrl
{
    /** AI Worker의 BLOCKED_HOST_SUFFIXES와 같게 유지한다. */
    private const NAVER_SUFFIXES = ['naver.com', 'naver.me', 'naver.net'];

    public static function normalize(string $url): string
    {
        $url = trim($url);
        $url = preg_replace('/#.*$/', '', $url) ?? $url;

        return self::canonicalNaver($url) ?? $url;
    }

    /**
     * 네이버 블로그 글은 주소 모양이 여러 가지다(PC·모바일·PostView). 같은 글이면 같은 주소가 되게 맞춘다:
     * https://blog.naver.com/{아이디}/{글번호}
     */
    public static function canonicalNaver(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (! in_array($host, ['blog.naver.com', 'm.blog.naver.com'], true)) {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        if (! empty($query['blogId']) && ! empty($query['logNo']) && ctype_digit((string) $query['logNo'])) {
            return "https://blog.naver.com/{$query['blogId']}/{$query['logNo']}";
        }
        // blog.naver.com/{아이디}?logNo={글번호}
        if (! empty($query['logNo']) && ctype_digit((string) $query['logNo']) && preg_match('#^/([A-Za-z0-9_\-]+)/?$#', $parts['path'] ?? '', $m)) {
            return "https://blog.naver.com/{$m[1]}/{$query['logNo']}";
        }
        if (preg_match('#^/([A-Za-z0-9_\-]+)/(\d+)/?$#', $parts['path'] ?? '', $m)) {
            return "https://blog.naver.com/{$m[1]}/{$m[2]}";
        }

        return null;
    }

    public static function isNaver(string $url): bool
    {
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));

        foreach (self::NAVER_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }
}
