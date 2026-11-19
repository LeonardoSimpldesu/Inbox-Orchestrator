<?php

namespace App\Jobs;

use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use App\Models\EmailSender;
use App\Services\Unsubscribe\MailtoUnsubscriber;
use App\Services\Unsubscribe\OneClickUnsubscriber;
use App\Services\Unsubscribe\SenderMailCleaner;
use App\Services\Unsubscribe\UnsubscribeFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UnsubscribeSenderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $senderId,
        public readonly string $method,
        public readonly string $cleanup,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("unsubscribe-sender-{$this->senderId}"))->dontRelease()];
    }

    public function handle(OneClickUnsubscriber $oneClick, MailtoUnsubscriber $mailto, SenderMailCleaner $cleaner): void
    {
        $sender = EmailSender::with('account.user')->find($this->senderId);

        if ($sender === null) {
            return;
        }

        $account = $sender->account;

        if ($account === null || $account->user->isDisabled()) {
            $this->markFailed($sender, 'account_unavailable');

            return;
        }

        if ($account->needsReauth()) {
            $this->markFailed($sender, 'reauth_required');

            return;
        }

        try {
            match ($this->method) {
                'one_click' => $oneClick->unsubscribe((string) $sender->unsubscribe_url),
                'mailto' => $mailto->unsubscribe($account, (string) $sender->unsubscribe_mailto),
                default => null,
            };
        } catch (UnsubscribeFailed|ProviderUnavailable|ReauthorizationRequired $e) {
            $this->markFailed($sender, $this->errorCode($e));

            return;
        }

        if (in_array($this->method, ['one_click', 'mailto'], true)) {
            $sender->forceFill([
                'unsubscribe_status' => EmailSender::STATUS_REQUESTED,
                'unsubscribe_method' => $this->method,
                'unsubscribe_requested_at' => now(),
                'unsubscribe_error' => null,
            ])->save();
        }

        try {
            $cleaner->clean($sender, $this->cleanup);
        } catch (ProviderUnavailable|ReauthorizationRequired $e) {
            $code = 'cleanup_'.$this->errorCode($e);
            $sender->forceFill(['unsubscribe_error' => $code])->save();
            Log::warning('Unsubscribe cleanup failed.', ['sender_id' => $sender->id, 'error' => $code]);
        }
    }

    private function markFailed(EmailSender $sender, string $code): void
    {
        $sender->forceFill(['unsubscribe_status' => EmailSender::STATUS_FAILED, 'unsubscribe_error' => $code])->save();

        Log::warning('Unsubscribe failed.', ['sender_id' => $sender->id, 'error' => $code]);
    }

    private function errorCode(RuntimeException $e): string
    {
        return $e instanceof ReauthorizationRequired ? 'reauth_required' : $e->getMessage();
    }
}
