<?php

namespace App\Services\Providers;

use Carbon\CarbonImmutable;

final readonly class MessageMetadata
{
    /**
     * @param  list<string>  $labelIds
     */
    public function __construct(
        public string $id,
        public string $threadId,
        public array $labelIds,
        public CarbonImmutable $internalDate,
        public ?string $from,
        public ?string $subject,
        public ?string $listUnsubscribe,
        public ?string $listUnsubscribePost,
    ) {}
}
