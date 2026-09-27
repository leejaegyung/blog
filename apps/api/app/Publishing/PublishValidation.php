<?php

namespace App\Publishing;

final readonly class PublishValidation
{
    /**
     * @param  list<string>  $errors  진행할 수 없는 문제
     * @param  list<string>  $warnings  진행은 되지만 확인이 필요한 문제
     */
    public function __construct(public array $errors = [], public array $warnings = []) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }
}
