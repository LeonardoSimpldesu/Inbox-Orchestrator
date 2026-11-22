<?php

namespace App\Jobs;

use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use App\Jobs\Concerns\RunsPerSender;
use App\Services\Unsubscribe\SenderMailCleaner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CleanSenderMailJob implements ShouldQueue
{
    use Queueable, RunsPerSender;

    public function __construct(
        public readonly int $senderId,
        public readonly int $accountId,
        public readonly string $cleanup,
    ) {}

    protected function lockName(): string
    {
        return 'sender-clean';
    }

    public function handle(SenderMailCleaner $cleaner): void
    {
        $sender = $this->usableSender();

        if ($sender === null) {
            return;
        }

        try {
            $cleaner->clean($sender, $this->cleanup);
        } catch (ProviderUnavailable|ReauthorizationRequired $e) {
            $this->logFailure('Cleaning sender mail failed.', $e);
        }
    }
}
