<?php

namespace App\Support;

/**
 * content_json 블록 목록을 텍스트로 풀고 수치를 계산한다.
 * AI Worker의 draft.render_text와 같은 규칙을 따른다(목록은 "- 항목", 사진은 제외).
 */
class PostContent
{
    public const BLOCK_TYPES = ['heading', 'paragraph', 'image', 'list', 'quote'];

    /** @param  list<array<string, mixed>>  $blocks */
    public static function text(?string $title, array $blocks): string
    {
        $lines = $title !== null && $title !== '' ? [$title] : [];
        foreach ($blocks as $block) {
            match ($block['type']) {
                'list' => array_push($lines, ...array_map(fn ($item) => '- '.$item, $block['items'] ?? [])),
                'image' => null,
                default => $lines[] = $block['text'] ?? '',
            };
        }

        return implode("\n", $lines);
    }

    /** @param  list<array<string, mixed>>  $blocks */
    public static function charCount(array $blocks): int
    {
        return mb_strlen(str_replace("\n", '', self::text(null, $blocks)));
    }

    /** @param  list<array<string, mixed>>  $blocks */
    public static function keywordCount(array $blocks, string $keyword): int
    {
        $keyword = trim(preg_replace('/\s+/u', ' ', $keyword) ?? '');

        return $keyword === '' ? 0 : mb_substr_count(self::text(null, $blocks), $keyword);
    }
}
