<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePostImageRequest extends FormRequest
{
    public const MAX_IMAGES = 30;

    public const MAX_KILOBYTES = 20 * 1024;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 확장자가 아니라 파일 내용으로 판별한 MIME을 검사한다.
            'image' => [
                'required', 'file', 'max:'.self::MAX_KILOBYTES,
                'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif',
            ],
            'strip_exif' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->route('post')->images()->count() >= self::MAX_IMAGES) {
                    $validator->errors()->add('image', '사진은 글 하나에 최대 '.self::MAX_IMAGES.'장까지 올릴 수 있습니다.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'image.mimetypes' => 'JPG, PNG, WEBP, HEIC 사진만 올릴 수 있습니다.',
            'image.max' => '사진 한 장은 20MB 이하여야 합니다.',
        ];
    }
}
