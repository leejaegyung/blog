<?php

namespace App\Support;

/**
 * 두 글이 얼마나 같은 문장으로 되어 있는지(0~1). 연속된 세 낱말 묶음이 겹치는 비율(짧은 글 기준).
 * 같은 주제라 키워드가 겹치는 정도로는 낮고, 문장을 베끼면 높다.
 */
final class TextOverlap
{
    public static function ratio(string $a, string $b): ?float
    {
        $x = self::shingles($a);
        $y = self::shingles($b);
        if ($x === [] || $y === []) {
            return null;
        }

        return round(count(array_intersect_key($x, $y)) / min(count($x), count($y)), 3);
    }

    /** @return array<string, true> */
    private static function shingles(string $text): array
    {
        $words = preg_split('/\s+/u', trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($text))), -1, PREG_SPLIT_NO_EMPTY);
        $set = [];
        for ($i = 0; $i + 2 < count($words); $i++) {
            $set[$words[$i].' '.$words[$i + 1].' '.$words[$i + 2]] = true;
        }

        return $set;
    }
}
