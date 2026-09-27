<?php

namespace App\Publishing;

use App\Models\Post;
use App\Services\QualityGate;

/**
 * 네이버 에디터에 직접 붙여넣도록 본문을 내보낸다(MVP 기본 게시 방식).
 * 사진은 로그인해야 받을 수 있는 주소라 붙여넣기로 넘어가지 않으므로, 본문에는 [사진 N] 자리 표시를 넣고
 * 사진은 같은 번호의 파일로 따로 내려받게 한다.
 */
class ManualExportPublisher implements Publisher
{
    public function key(): string
    {
        return 'manual_export';
    }

    public function validate(Post $post): PublishValidation
    {
        $post->loadMissing(['facts', 'images']);
        $blocks = $post->content_json['blocks'] ?? [];
        $errors = [];
        $warnings = [];

        if ($blocks === []) {
            $errors[] = '내보낼 본문이 없습니다.';
        }
        if (! $post->title) {
            $errors[] = '제목이 없습니다.';
        }

        $quality = $post->quality_json;
        if (! $quality) {
            $warnings[] = '게시 전 검사를 하지 않았습니다.';
        } else {
            $blocking = collect($quality['issues'] ?? [])->where('severity', 'error')->count();
            if ($blocking > 0) {
                $warnings[] = "게시 전 검사에서 '꼭 고치기' {$blocking}개가 남아 있습니다.";
            }
            if (($quality['source_hash'] ?? null) !== QualityGate::sourceHash($post)) {
                $warnings[] = '검사한 뒤 본문이 바뀌었습니다. 다시 검사하는 것이 좋습니다.';
            }
        }

        return new PublishValidation($errors, $warnings);
    }

    public function publish(Post $post): PublishResult
    {
        $exporter = new PostExporter($post);

        return new PublishResult('exported', [
            'html' => $exporter->html(),
            'text' => $exporter->text(),
            'tags' => $post->content_json['tags'] ?? [],
            'photos' => $exporter->photos(),
        ]);
    }
}
