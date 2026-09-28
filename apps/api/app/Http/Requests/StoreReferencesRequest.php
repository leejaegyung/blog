<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreReferencesRequest extends FormRequest
{
    public const MAX_PER_PROJECT = 50;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'urls' => ['required_without:text', 'prohibits:text', 'array', 'min:1', 'max:'.self::MAX_PER_PROJECT],
            'urls.*' => ['required', 'string', 'max:2000', 'url:http,https'],
            'text' => ['required_without:urls', 'string', 'min:20', 'max:100000'],
            'title' => ['nullable', 'string', 'max:200'],
            // 본문과 함께 원래 글 주소(북마크 버튼 "Blog AI로 보내기"). 서버는 이 주소에 접속하지 않는다
            'source_url' => ['nullable', 'prohibits:urls', 'string', 'max:2000', 'url:http,https'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $adding = $this->has('urls') ? count((array) $this->input('urls')) : 1;
                $existing = $this->route('project')->references()->count();
                if ($existing + $adding > self::MAX_PER_PROJECT) {
                    $validator->errors()->add(
                        $this->has('urls') ? 'urls' : 'text',
                        '참고자료는 프로젝트당 최대 '.self::MAX_PER_PROJECT.'개입니다. (현재 '.$existing.'개)',
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'urls.*.url' => '올바른 주소가 아닙니다: :input',
            'text.min' => '본문이 너무 짧습니다.',
        ];
    }
}
