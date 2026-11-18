<?php

namespace App\Services\Providers;

final readonly class MessagePage
{
    /**
     * @param  list<string>  $ids
     */
    public function __construct(
        public array $ids,
        public ?string $nextPageToken,
    ) {}
}
