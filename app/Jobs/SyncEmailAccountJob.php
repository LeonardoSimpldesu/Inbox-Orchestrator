<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Services\EmailSyncService;
use App\Services\Sync\SyncMode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Receives the account id, not the model, so tokens never end up in the queue payload.
 */
class SyncEmailAccountJob implements ShouldQueue
{
    use Queueable;

    /** Must stay below the queue's retry_after (DB_QUEUE_RETRY_AFTER). */
    public int $timeout = 1500;

    public int $maxExceptions = 1;

    public function __construct(
        public readonly int $accountId,
        public readonly SyncMode $mode = SyncMode::Recent,
    ) {
        $this->onQueue('sync');
    }

    /**
     * Manual runs wait for a busy account instead of being dropped.
     */
    public function tries(): int
    {
        return $this->mode->isScheduled() ? 1 : 5;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        $lock = (new WithoutOverlapping("sync-account-{$this->accountId}"))->expireAfter(1600);

        return [$this->mode->isScheduled() ? $lock->dontRelease() : $lock->releaseAfter(60)];
    }

    public function handle(EmailSyncService $service): void
    {
        $account = $this->syncableAccount();

        if ($account !== null) {
            $service->run($account, $this->mode);
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($this->mode === SyncMode::HistoryNow) {
            EmailAccount::whereKey($this->accountId)->update(['history_import_requested_at' => null]);
        }
    }

    public function shouldRun(): bool
    {
        return $this->syncableAccount() !== null;
    }

    private function syncableAccount(): ?EmailAccount
    {
        $account = EmailAccount::with('user')->find($this->accountId);

        return $account !== null && ! $account->user->isDisabled() && ! $account->needsReauth() ? $account : null;
    }
}
