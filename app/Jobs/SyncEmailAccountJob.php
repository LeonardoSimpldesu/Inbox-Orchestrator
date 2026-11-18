<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Receives the account id, not the model, so tokens never end up in the queue payload.
 */
class SyncEmailAccountJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $accountId) {}

    public function handle(): void
    {
        if (! $this->shouldRun()) {
            return;
        }

        // Metadata sync is implemented in the next Phase 1 stage.
    }

    public function shouldRun(): bool
    {
        $account = EmailAccount::with('user')->find($this->accountId);

        return $account !== null
            && ! $account->user->isDisabled()
            && ! $account->needsReauth();
    }
}
