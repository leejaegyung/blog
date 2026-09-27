<?php

namespace App\Support;

use App\Models\Post;

/**
 * 계획이 가리키는 사실 항목·사진이 지금도 글에 있는지 확인하고, 미사용 목록을 다시 계산한다.
 * (계획을 만든 뒤 사실을 고치거나 사진을 지울 수 있다)
 */
class PlanIntegrity
{
    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function refresh(array $plan, Post $post): array
    {
        $factKeys = $post->facts()->pluck('fact_key')->all();
        $imageIds = $post->images()->pluck('id')->all();
        $used = collect($plan['outline'] ?? [])->flatMap(fn ($s) => $s['fact_keys'] ?? [])->unique()->all();
        $placed = collect($plan['outline'] ?? [])->flatMap(fn ($s) => $s['image_ids'] ?? [])->all();

        return array_merge($plan, [
            'unused_fact_keys' => array_values(array_diff($factKeys, $used)),
            'unplaced_image_ids' => array_values(array_diff($imageIds, $placed)),
        ]);
    }

    /** 계획이 지금은 없는 사실 항목이나 사진을 가리키는가 */
    public static function isStale(?array $plan, Post $post): bool
    {
        if (! $plan) {
            return false;
        }

        $factKeys = $post->facts->pluck('fact_key')->all();
        $imageIds = $post->images->pluck('id')->all();

        foreach ($plan['outline'] ?? [] as $section) {
            if (array_diff($section['fact_keys'] ?? [], $factKeys) || array_diff($section['image_ids'] ?? [], $imageIds)) {
                return true;
            }
        }

        return (bool) array_diff($plan['required_fact_keys'] ?? [], $factKeys);
    }
}
