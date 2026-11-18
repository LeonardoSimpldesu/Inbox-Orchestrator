<?php

namespace App\Services\Sync;

enum SyncMode: string
{
    case Recent = 'recent';
    case Changes = 'changes';
    case History = 'history';
    case HistoryNow = 'history_now';

    public function messageLimit(): ?int
    {
        return match ($this) {
            self::Recent => 5000,
            self::History => 500,
            self::Changes, self::HistoryNow => null,
        };
    }

    public function timeLimitSeconds(): int
    {
        return 20 * 60;
    }

    /**
     * Scheduled runs are cheap to skip: the next tick picks up where they stopped.
     */
    public function isScheduled(): bool
    {
        return $this === self::Changes || $this === self::History;
    }
}
