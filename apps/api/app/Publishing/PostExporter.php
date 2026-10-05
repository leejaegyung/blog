<?php

namespace App\Publishing;

use App\Models\Post;
use App\Support\Platform;
use App\Models\PostImage;

/** content_json → 붙여넣기용 HTML·텍스트. 사진은 본문에 나오는 순서대로 1번부터 번호를 붙인다. */
class PostExporter
{
    /** @var array<int, int> image_id => 번호 */
    private array $numbers = [];

    public function __construct(private Post $post)
    {
        $number = 0;
        foreach ($this->blocks() as $block) {
            if ($block['type'] === 'image' && ! isset($this->numbers[$block['image_id']])) {
                $this->numbers[$block['image_id']] = ++$number;
            }
        }
    }

    /**
     * 네이버 편집기(스마트에디터 ONE)는 붙여넣은 h2·ul·blockquote와 문단 사이 여백을 버리고 일반 글로 만든다.
     * 그래서 네이버용은 소제목을 굵고 큰 글씨(편집기가 붙여넣기에서 지키는 서식)로, 목록은 "•" 줄로, 문단 사이에는 빈 줄을 넣는다.
     */
    private function forNaver(): bool
    {
        return $this->post->platform !== Platform::TISTORY;
    }

    public function html(): string
    {
        $naver = $this->forNaver();
        $html = [];
        foreach ($this->blocks() as $block) {
            $html[] = match (true) {
                $naver && $block['type'] === 'heading' => '<p><span style="font-size:19px;"><b>'.$this->escape($block['text'] ?? '').'</b></span></p>',
                $naver && $block['type'] === 'list' => '<p>'.implode('<br>', array_map(fn ($item) => '• '.$this->escape($item), $block['items'] ?? [])).'</p>',
                $naver && $block['type'] === 'quote' => '<p><b>“'.$this->lines($block['text'] ?? '').'”</b></p>',
                default => match ($block['type']) {
                    'heading' => '<h2>'.$this->escape($block['text'] ?? '').'</h2>',
                    'paragraph' => '<p>'.$this->lines($block['text'] ?? '').'</p>',
                    'list' => '<ul>'.implode('', array_map(fn ($item) => '<li>'.$this->escape($item).'</li>', $block['items'] ?? [])).'</ul>',
                    'quote' => '<blockquote><p>'.$this->lines($block['text'] ?? '').'</p></blockquote>',
                    // data-photo: 화면에서 이 자리를 실제 사진으로 바꾸거나 조각 붙여넣기에서 사진 한 장으로 나눈다
                    'image' => '<p data-photo="'.($this->numbers[$block['image_id']] ?? 0).'"><strong>'.$this->marker($block['image_id']).'</strong></p>',
                    default => '',
                },
            };
        }

        // 3단계에서 연결한 장소: 본문 끝(해시태그 앞)에 위치 안내(여러 곳이면 하나씩)
        foreach ($this->places() as $place) {
            $lines = ['<strong>📍 '.$this->escape($place['label']).'</strong>', ...array_map(fn ($line) => $this->escape($line), $place['lines'])];
            if ($place['url']) {
                $url = $this->escape($place['url']);
                $lines[] = "지도: <a href=\"{$url}\">{$url}</a>";
            }
            $html[] = '<p>'.implode('<br>', $lines).'</p>';
        }

        // 티스토리는 태그를 본문이 아니라 태그 칸에 넣는다
        $tags = $this->bodyTags();
        if ($tags !== '') {
            $html[] = '<p>'.$this->escape($tags).'</p>';
        }

        // 네이버는 붙여넣으면 문단 여백이 사라져 블록 사이에 빈 줄을 넣는다
        return implode($naver ? "\n<p><br></p>\n" : "\n", $html);
    }

    public function text(): string
    {
        $parts = [$this->post->title ?? ''];
        foreach ($this->blocks() as $block) {
            $parts[] = match ($block['type']) {
                'list' => implode("\n", array_map(fn ($item) => '- '.$item, $block['items'] ?? [])),
                'image' => $this->marker($block['image_id']),
                default => $block['text'] ?? '',
            };
        }
        foreach ($this->places() as $place) {
            $parts[] = implode("\n", ['📍 '.$place['label'], ...$place['lines'], ...($place['url'] ? ['지도: '.$place['url']] : [])]);
        }
        $parts[] = $this->bodyTags();

        return implode("\n\n", array_filter($parts, fn ($part) => $part !== ''));
    }

    /** @return list<array{number: int, image_id: int, filename: string, url: string}> */
    public function photos(): array
    {
        $images = $this->post->images()->whereKey(array_keys($this->numbers))->get()->keyBy('id');

        return collect($this->numbers)
            ->filter(fn ($number, $id) => $images->has($id))
            ->map(fn ($number, $id) => [
                'number' => $number,
                'image_id' => $id,
                'filename' => self::filename($number),
                'url' => "/api/posts/{$this->post->id}/images/{$id}/file",
            ])
            ->values()
            ->all();
    }

    /** @return array<int, PostImage> 번호 => 사진 */
    public function numberedImages(): array
    {
        $images = $this->post->images()->whereKey(array_keys($this->numbers))->get()->keyBy('id');
        $numbered = [];
        foreach ($this->numbers as $id => $number) {
            if ($images->has($id)) {
                $numbered[$number] = $images[$id];
            }
        }

        return $numbered;
    }

    public static function filename(int $number): string
    {
        return sprintf('%02d.jpg', $number);
    }

    /** @return list<array<string, mixed>> */
    private function blocks(): array
    {
        return $this->post->content_json['blocks'] ?? [];
    }

    /**
     * 연결한 장소마다 안내 줄(이름·주소·전화)과 지도 링크. 지도 링크는 붙여넣은 링크, 없으면 카카오맵.
     * 장소가 하나면 "위치", 여러 곳이면 "위치 1"·"위치 2".
     *
     * @return list<array{label: string, lines: list<string>, url: ?string}>
     */
    private function places(): array
    {
        $result = [];
        foreach ($this->post->places() as $place) {
            $lines = array_values(array_filter([
                $place['name'] ?? null,
                ($place['road_address'] ?? null) ?: ($place['address'] ?? null),
                isset($place['phone']) && $place['phone'] ? '전화 '.$place['phone'] : null,
            ]));
            $url = collect([$place['map_url'] ?? null, $place['kakao_url'] ?? null])
                ->first(fn ($u) => is_string($u) && preg_match('#^https?://#i', $u));
            if ($lines !== [] || $url) {
                $result[] = ['lines' => $lines, 'url' => $url];
            }
        }
        $many = count($result) > 1;

        return array_map(fn ($place, $i) => ['label' => $many ? '위치 '.($i + 1) : '위치', ...$place], $result, array_keys($result));
    }

    private function marker(int $imageId): string
    {
        return '[사진 '.($this->numbers[$imageId] ?? '?').']';
    }

    private function bodyTags(): string
    {
        return $this->post->platform === Platform::TISTORY ? '' : $this->tags();
    }

    private function tags(): string
    {
        return implode(' ', array_map(fn ($tag) => '#'.$tag, $this->post->content_json['tags'] ?? []));
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function lines(string $text): string
    {
        return implode('<br>', array_map(fn ($line) => $this->escape($line), explode("\n", $text)));
    }
}
