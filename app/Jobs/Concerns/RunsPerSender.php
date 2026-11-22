<?php

namespace App\Jobs\Concerns;

use App\Exceptions\ReauthorizationRequired;
use App\Models\EmailSender;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sender jobs that change labels in Gmail: one run per sender at a time, and
 * never alongside a sync of the same account (both write the local labels).
 */
trait RunsPerSender
{
    /** Retries only cover waiting for the account lock; a real failure stops at the first exception. */
    public int $tries = 10;

    public int $maxExceptions = 1;

    /** Must stay below the queue's retry_after (DB_QUEUE_RETRY_AFTER). */
    public int $timeout = 1500;

    abstract protected function lockName(): string;

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("{$this->lockName()}-{$this->senderId}"))->expireAfter(1600)->dontRelease(),
            (new WithoutOverlapping("sync-account-{$this->accountId}"))->expireAfter(1600)->releaseAfter(60),
        ];
    }

    /**
     * The sender with its account, or null when the account can't be used now.
     */
    protected function usableSender(): ?EmailSender
    {
        $sender = EmailSender::with('account.user')->find($this->senderId);
        $account = $sender?->account;

        return $account !== null && ! $account->user->isDisabled() && ! $account->needsReauth() ? $sender : null;
    }

    protected function logFailure(string $message, RuntimeException $e): void
    {
        $code = $e instanceof ReauthorizationRequired ? 'reauth_required' : $e->getMessage();

        Log::warning($message, ['sender_id' => $this->senderId, 'error' => $code]);
    }
}
