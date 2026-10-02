<?php

namespace App\Http\Requests;

use App\Enums\Tone;
use App\Support\PostContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PostRequest extends FormRequest
{
    public const MAX_FACTS = 30;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->route('post') === null;

        return [
            'keyword_project_id' => [
                $creating ? 'required' : 'prohibited',
                'integer',
                Rule::exists('keyword_projects', 'id')->where('user_id', $this->user()->id),
            ],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'tone' => ['sometimes', 'nullable', Rule::enum(Tone::class)],
            'target_length' => ['sometimes', 'nullable', 'integer', 'between:500,10000'],
            'facts' => ['sometimes', 'array', 'max:'.self::MAX_FACTS],
            'facts.*.fact_key' => ['required', 'string', 'max:50'],
            'facts.*.fact_value' => ['required', 'string', 'max:1000'],
            // 3단계 "장소 연결"에서 고른 장소(null이면 해제). 이름·주소 등은 사실 항목으로도 들어가 사용자가 확인한다
            'place' => ['sometimes', 'nullable', 'array'],
            'place.name' => ['nullable', 'string', 'max:100'],
            'place.category' => ['nullable', 'string', 'max:100'],
            'place.phone' => ['nullable', 'string', 'max:30'],
            'place.address' => ['nullable', 'string', 'max:200'],
            'place.road_address' => ['nullable', 'string', 'max:200'],
            'place.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'place.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'place.kakao_id' => ['nullable', 'string', 'max:30'],
            'place.kakao_url' => ['nullable', 'string', 'max:300', 'url:http,https'],
            'place.map_url' => ['nullable', 'string', 'max:2000', 'url:http,https'],
            'place.source' => ['nullable', Rule::in(['naver', 'google', 'kakao', 'other', 'text'])],
            // 편집기 저장: 블록 목록과 태그. 사진은 이 글의 것만
            'content' => [$creating ? 'prohibited' : 'sometimes', 'array'],
            'content.blocks' => ['required_with:content', 'array', 'max:500'],
            'content.blocks.*.type' => ['required', Rule::in(PostContent::BLOCK_TYPES)],
            'content.blocks.*.text' => ['nullable', 'string', 'max:5000'],
            'content.blocks.*.image_id' => [
                'nullable', 'required_if:content.blocks.*.type,image', 'integer',
                Rule::in($creating ? [] : $this->route('post')->images()->pluck('id')->all()),
            ],
            'content.blocks.*.items' => ['nullable', 'array', 'max:50'],
            'content.blocks.*.items.*' => ['string', 'max:1000'],
            'content.tags' => ['present_with:content', 'array', 'max:30'],
            'content.tags.*' => ['string', 'max:50'],
        ];
    }

    public function attributes(): array
    {
        return [
            'keyword_project_id' => '프로젝트',
            'facts.*.fact_key' => '항목 이름',
            'facts.*.fact_value' => '항목 내용',
            'content.blocks.*.image_id' => '사진',
        ];
    }
}
