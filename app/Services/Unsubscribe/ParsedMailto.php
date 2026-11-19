<?php

namespace App\Services\Unsubscribe;

final readonly class ParsedMailto
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
    ) {}
}
