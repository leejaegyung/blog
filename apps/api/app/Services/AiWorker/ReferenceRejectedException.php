<?php

namespace App\Services\AiWorker;

use RuntimeException;

/** 다시 시도해도 결과가 같은 실패(404, 네이버, robots 금지, 본문 없음 등). */
class ReferenceRejectedException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
