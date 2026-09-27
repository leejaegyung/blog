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
