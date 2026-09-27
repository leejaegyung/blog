<?php

namespace App\Support;

class ReferenceUrl
{
    /** AI Worker의 BLOCKED_HOST_SUFFIXES와 같게 유지한다. */
    private const NAVER_SUFFIXES = ['naver.com', 'naver.me', 'naver.net'];

    public static function normalize(string $url): string
    {
        $url = trim($url);

        return preg_replace('/#.*$/', '', $url) ?? $url;
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
