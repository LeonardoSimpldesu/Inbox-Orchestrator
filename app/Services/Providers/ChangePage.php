<?php

namespace App\Services\Providers;

final readonly class ChangePage
{
    /**
     * @param  list<string>  $changedIds
     * @param  list<string>  $deletedIds
     */
    public function __construct(
        public array $changedIds,
        public array $deletedIds,
        public string $historyId,
        public ?string $nextPageToken,
    ) {}
}
