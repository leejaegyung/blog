<?php

namespace App\Support;

/** 올릴 곳. 네이버 블로그와 티스토리는 학습 카테고리·분석·올리기가 따로다 */
final class Platform
{
    public const NAVER = 'naver';

    public const TISTORY = 'tistory';

    public const ALL = [self::NAVER, self::TISTORY];

    public static function label(string $platform): string
    {
        return $platform === self::TISTORY ? '티스토리' : '네이버';
    }

    /** 글 주소로 어느 플랫폼 글인지(티스토리 블로그는 *.tistory.com) */
    public static function fromUrl(?string $url): ?string
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

        return match (true) {
            $host === '' => null,
            str_ends_with($host, 'blog.naver.com') => self::NAVER,
            str_ends_with($host, '.tistory.com') => self::TISTORY,
            default => null,
        };
    }
}
