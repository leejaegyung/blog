<?php

namespace App\Publishing;

use App\Models\Post;
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

    public function html(): string
    {
        $html = [];
        foreach ($this->blocks() as $block) {
            $html[] = match ($block['type']) {
                'heading' => '<h2>'.$this->escape($block['text'] ?? '').'</h2>',
                'paragraph' => '<p>'.$this->lines($block['text'] ?? '').'</p>',
                'list' => '<ul>'.implode('', array_map(fn ($item) => '<li>'.$this->escape($item).'</li>', $block['items'] ?? [])).'</ul>',
                'quote' => '<blockquote><p>'.$this->lines($block['text'] ?? '').'</p></blockquote>',
                // data-photo: 화면에서 이 자리를 실제 사진(data URI)으로 바꿔 한 번에 붙여넣게 한다
                'image' => '<p data-photo="'.($this->numbers[$block['image_id']] ?? 0).'"><strong>'.$this->marker($block['image_id']).'</strong></p>',
                default => '',
            };
        }

        $tags = $this->tags();
        if ($tags !== '') {
            $html[] = '<p>'.$this->escape($tags).'</p>';
        }

        return implode("\n", $html);
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
        $parts[] = $this->tags();

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

    private function marker(int $imageId): string
    {
        return '[사진 '.($this->numbers[$imageId] ?? '?').']';
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
