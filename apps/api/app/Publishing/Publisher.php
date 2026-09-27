<?php

namespace App\Publishing;

use App\Models\Post;

/** 게시 방식 (기획서 17장). 네이버 자동 게시는 공식·허용 방식이 확인되기 전까지 구현하지 않는다. */
interface Publisher
{
    public function key(): string;

    public function validate(Post $post): PublishValidation;

    public function publish(Post $post): PublishResult;
}
