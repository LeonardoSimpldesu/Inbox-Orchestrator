<?php

namespace App\Jobs;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use App\Jobs\Concerns\RunsPerSender;
use App\Models\EmailSender;
use App\Services\Sync\MessageStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Moves a sender's existing mail from its old category label to the new one;
 * the category rule takes care of future mail.
 */
class ApplySenderLabelJob implements ShouldQueue
{
    use Queueable, RunsPerSender;

    public function __construct(
        public readonly int $senderId,
        public readonly int $accountId,
        public readonly ?string $newLabel,
        public readonly ?string $oldLabel,
    ) {}

    protected function lockName(): string
    {
        return 'sender-label';
    }

    public function handle(EmailProviderInterface $provider, MessageStore $store): void
    {
        $sender = $this->usableSender();

        try {
            if ($sender !== null) {
                $add = array_filter([$this->newLabel]);
                $remove = array_filter([$this->oldLabel]);
                $ids = $provider->listAllMessageIds($sender->account, 'from:'.$sender->email);

                $provider->batchModify($sender->account, $ids, array_values($add), array_values($remove));
                $store->applyLabelChange($sender->account, $ids, array_values($add), array_values($remove));
            }
        } catch (ProviderUnavailable|ReauthorizationRequired $e) {
            $this->logFailure('Applying sender label failed.', $e);
        } finally {
            $this->clearApplying();
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->clearApplying();
    }

    private function clearApplying(): void
    {
        EmailSender::whereKey($this->senderId)->update(['label_applying_at' => null]);
    }
}
