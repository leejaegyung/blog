<?php

namespace App\Publishing;

final readonly class PublishResult
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(public string $status, public array $payload = [], public ?string $url = null) {}
}
