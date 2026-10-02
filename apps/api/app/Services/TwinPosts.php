<?php

namespace App\Services;

use App\Models\KeywordProject;
use App\Models\Post;
use App\Support\Platform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 짝 글: 같은 경험(키워드·사실·사진)을 다른 플랫폼에 올릴 글. 원래 글에서 사실과 사진을 가져와 그 플랫폼 기준으로 따로 쓴다.
 * 같은 글을 두 곳에 그대로 올리면 검색엔진이 중복 문서로 보고 둘 다 밀어내기 때문이다.
 */
class TwinPosts
{
    public static function otherPlatform(string $platform): string
    {
        return $platform === Platform::TISTORY ? Platform::NAVER : Platform::TISTORY;
    }

    /** 짝 글을 만든다(이미 있으면 그대로). 학습 카테고리는 짝 글 플랫폼용이어야 한다(호출하는 쪽이 확인) */
    public function ensure(Post $primary, ?KeywordProject $learning = null): Post
    {
        $primary->loadMissing('project');
        $twin = $primary->twin;
        $platform = self::otherPlatform($primary->platform);

        if (! $twin) {
            $project = $primary->user->keywordProjects()->firstOrCreate(
                ['kind' => KeywordProject::KIND_KEYWORD, 'platform' => $platform, 'keyword' => $primary->project->keyword],
                ['category' => $learning?->keyword ?? $primary->project->category],
            );
            $twin = $primary->user->posts()->create([
                'keyword_project_id' => $project->id,
                'platform' => $platform,
                'tone' => $primary->tone,
                'target_length' => $primary->target_length,
            ]);
            $twin->forceFill(['twin_of_post_id' => $primary->id])->save();
            $primary->setRelation('twin', $twin);
        }
        if ($learning) {
            $twin->project->update(['learning_category_id' => $learning->id, 'category' => $learning->keyword]);
        }

        return $twin->refresh();
    }

    /** 원래 글의 사실·말투·길이·사진 구성 */
    public function signature(Post $primary): string
    {
        $facts = $primary->facts()->orderBy('sort_order')->get(['fact_key', 'fact_value'])->map(fn ($f) => $f->fact_key.'='.$f->fact_value);
        $images = $primary->images()->pluck('id');

        return hash('sha256', json_encode([$primary->tone, $primary->target_length, $facts, $images, $primary->place_json]));
    }

    /**
     * 사실·사진을 원래 글에 맞춘다. 사진 파일은 따로 복사한다(어느 쪽을 지워도 다른 쪽 사진은 남는다).
     *
     * @return bool 가져온 내용이 바뀌었으면 true
     */
    public function sync(Post $primary, Post $twin): bool
    {
        $signature = $this->signature($primary);
        if ($twin->twin_source_hash === $signature) {
            return false;
        }

        $disk = Storage::disk('uploads');
        $removedFiles = [];
        DB::transaction(function () use ($primary, $twin, $signature, $disk, &$removedFiles) {
            $twin->facts()->delete();
            foreach ($primary->facts()->orderBy('sort_order')->get() as $fact) {
                $twin->facts()->create($fact->only(['fact_key', 'fact_value', 'source_type', 'verified', 'sort_order']));
            }

            $sources = $primary->images()->get();
            $copies = $twin->images()->get()->keyBy('source_image_id');
            foreach ($sources as $source) {
                $copy = $copies->get($source->id);
                if ($copy) {
                    $copy->update(['sort_order' => $source->sort_order] + ($copy->vision_json ? [] : $source->only(['vision_json', 'vision_status', 'vision_error'])));

                    continue;
                }
                $prefix = "users/{$twin->user_id}/posts/{$twin->id}/".Str::uuid();
                $storageKey = $this->copyFile($source->storage_key, $prefix);
                $thumbKey = $source->thumb_key ? $this->copyFile($source->thumb_key, $prefix) : null;
                if ($storageKey === null) {
                    continue;
                }
                $twin->images()->create([
                    ...$source->only(['original_name', 'mime_type', 'width', 'height', 'size_bytes', 'taken_at', 'sort_order', 'vision_json', 'vision_status', 'vision_error', 'caption']),
                    'storage_key' => $storageKey,
                    'thumb_key' => $thumbKey,
                    'source_image_id' => $source->id,
                ]);
            }
            // 원래 글에서 뺀 사진은 짝 글에서도 뺀다
            $gone = $twin->images()->where(fn ($q) => $q->whereNull('source_image_id')->orWhereNotIn('source_image_id', $sources->modelKeys()))->get();
            foreach ($gone as $image) {
                $removedFiles = [...$removedFiles, ...array_filter([$image->storage_key, $image->thumb_key])];
                $image->delete();
            }

            $twin->forceFill(['tone' => $primary->tone, 'target_length' => $primary->target_length, 'place_json' => $primary->place_json, 'twin_source_hash' => $signature])->save();
        });
        $disk->delete($removedFiles);

        return true;
    }

    private function copyFile(string $key, string $prefix): ?string
    {
        $disk = Storage::disk('uploads');
        if (! $disk->exists($key)) {
            return null;
        }
        $target = $prefix.'/'.basename($key);
        $disk->copy($key, $target);

        return $target;
    }
}
