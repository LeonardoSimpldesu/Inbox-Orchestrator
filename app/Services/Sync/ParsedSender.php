<?php

namespace App\Services\Sync;

final readonly class ParsedSender
{
    public function __construct(
        public string $email,
        public string $domain,
        public ?string $name,
    ) {}
}
