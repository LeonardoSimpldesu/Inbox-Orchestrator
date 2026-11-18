<?php

namespace App\Services\Sync;

final readonly class ParsedUnsubscribe
{
    public function __construct(
        public ?string $mailto,
        public ?string $url,
        public bool $oneClick,
    ) {}
}
