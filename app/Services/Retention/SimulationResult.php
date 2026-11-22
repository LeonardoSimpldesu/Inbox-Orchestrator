<?php

namespace App\Services\Retention;

final readonly class SimulationResult
{
    /**
     * @param  list<array{sender: string, subject: ?string, date: string}>  $sample
     */
    public function __construct(
        public int $total,
        public bool $capped,
        public array $sample,
    ) {}
}
