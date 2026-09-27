<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('keyword')) {
            // "수원  인계동 파스타 " 같은 입력을 같은 키워드로 취급한다.
            $this->merge(['keyword' => preg_replace('/\s+/u', ' ', trim((string) $this->input('keyword')))]);
        }
    }

    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'keyword' => [
                $project ? 'sometimes' : 'required',
                'string',
                'max:100',
                Rule::unique('keyword_projects')
                    ->where('user_id', $this->user()->id)
                    ->ignore($project),
            ],
            'category' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return ['keyword.unique' => '이미 같은 키워드의 프로젝트가 있습니다.'];
    }
}
