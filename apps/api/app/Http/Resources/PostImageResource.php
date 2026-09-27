<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PostImage */
class PostImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $file = "/api/posts/{$this->post_id}/images/{$this->id}/file";
        // 파일 URL은 내용이 바뀌지 않으므로 갱신 시각을 붙여 캐시를 무효화한다.
        $version = $this->updated_at?->timestamp;

        return [
            'id' => $this->id,
            'url' => "{$file}?v={$version}",
            'thumb_url' => "{$file}?variant=thumb&v={$version}",
            'original_name' => $this->original_name,
            'width' => $this->width,
            'height' => $this->height,
            'size_bytes' => $this->size_bytes,
            'taken_at' => $this->taken_at,
            'sort_order' => $this->sort_order,
            'caption' => $this->caption,
            'vision' => $this->vision_json,
            'vision_status' => $this->vision_status,
            'vision_error' => $this->vision_error,
        ];
    }
}
