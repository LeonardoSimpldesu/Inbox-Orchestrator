<?php

namespace App\Services\Sync;

use App\Models\EmailAccount;
use App\Models\EmailMessageMeta;
use App\Models\EmailSender;
use App\Services\Providers\MessageMetadata;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persists provider batches with a constant number of queries per batch:
 * bulk upserts keyed by provider ids (re-reads never duplicate) and sender
 * totals recomputed from stored messages (never incremented).
 */
class MessageStore
{
    /**
     * Only imports that walk the mailbox by date extend coverage: a change to an
     * old message must not move oldest_synced_at back, or the history import
     * would skip everything between that message and the real oldest import.
     *
     * @param  list<MessageMetadata>  $batch
     */
    public function store(EmailAccount $account, array $batch, bool $extendsCoverage = true): void
    {
        if ($batch === []) {
            return;
        }

        DB::transaction(function () use ($account, $batch, $extendsCoverage) {
            $senders = $this->parseSenders($batch);
            $senderIds = $this->upsertSenders($account, $batch, $senders);

            $now = now();
            $rows = array_map(function (MessageMetadata $message) use ($account, $senders, $senderIds, $now) {
                $sender = $senders[$message->id];

                return [
                    'email_account_id' => $account->id,
                    'provider_message_id' => $message->id,
                    'provider_thread_id' => $message->threadId,
                    'email_sender_id' => $sender ? $senderIds[$sender->email] : null,
                    'sender_email' => $sender->email ?? '',
                    'sender_domain' => $sender->domain ?? '',
                    'subject' => HeaderParser::subject($message->subject),
                    'labels' => json_encode(array_values($message->labelIds)),
                    'received_at' => $this->toDatabase($message->internalDate),
                    'is_archived' => ! in_array('INBOX', $message->labelIds, true),
                    'is_deleted' => array_intersect(['TRASH', 'SPAM'], $message->labelIds) !== [],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $batch);

            EmailMessageMeta::upsert(
                $rows,
                ['email_account_id', 'provider_message_id'],
                ['provider_thread_id', 'email_sender_id', 'sender_email', 'sender_domain', 'subject', 'labels', 'received_at', 'is_archived', 'is_deleted', 'updated_at'],
            );

            $this->recount(array_values(array_unique($senderIds)));
            if ($extendsCoverage) {
                $this->moveOldestBack($account, $batch);
            }
        });
    }

    /**
     * @param  list<string>  $providerIds
     */
    public function delete(EmailAccount $account, array $providerIds): void
    {
        if ($providerIds === []) {
            return;
        }

        DB::transaction(function () use ($account, $providerIds) {
            $messages = EmailMessageMeta::where('email_account_id', $account->id)->whereIn('provider_message_id', $providerIds);

            $senderIds = (clone $messages)->whereNotNull('email_sender_id')->distinct()->pluck('email_sender_id')->all();
            $messages->delete();

            $this->recount($senderIds);
        });
    }

    /**
     * @param  list<MessageMetadata>  $batch
     * @return array<string, ParsedSender|null> keyed by message id
     */
    private function parseSenders(array $batch): array
    {
        $senders = [];

        foreach ($batch as $message) {
            $senders[$message->id] = HeaderParser::sender($message->from);
        }

        return $senders;
    }

    /**
     * Name and unsubscribe data come from each sender's newest message, and only
     * replace what is stored when that message is newer than the last one seen.
     *
     * @param  list<MessageMetadata>  $batch
     * @param  array<string, ParsedSender|null>  $senders
     * @return array<string, int> sender id keyed by email
     */
    private function upsertSenders(EmailAccount $account, array $batch, array $senders): array
    {
        $newest = [];

        foreach ($batch as $message) {
            $sender = $senders[$message->id];

            if ($sender !== null && (! isset($newest[$sender->email]) || $message->internalDate > $newest[$sender->email][0]->internalDate)) {
                $newest[$sender->email] = [$message, $sender];
            }
        }

        if ($newest === []) {
            return [];
        }

        $existing = EmailSender::where('email_account_id', $account->id)
            ->whereIn('email', array_keys($newest))
            ->get(['email', 'name', 'unsubscribe_mailto', 'unsubscribe_url', 'unsubscribe_one_click', 'last_email_received_at'])
            ->keyBy('email');

        $now = now();
        $rows = [];

        foreach ($newest as $email => [$message, $sender]) {
            $stored = $existing->get($email);
            $isNewer = $stored?->last_email_received_at === null || $message->internalDate->greaterThan($stored->last_email_received_at);
            $unsubscribe = HeaderParser::unsubscribe($message->listUnsubscribe, $message->listUnsubscribePost);

            $rows[] = [
                'email_account_id' => $account->id,
                'email' => $email,
                'domain' => $sender->domain,
                'name' => $isNewer ? $sender->name : $stored->name,
                'unsubscribe_mailto' => $isNewer ? $unsubscribe->mailto : $stored->unsubscribe_mailto,
                'unsubscribe_url' => $isNewer ? $unsubscribe->url : $stored->unsubscribe_url,
                'unsubscribe_one_click' => $isNewer ? $unsubscribe->oneClick : $stored->unsubscribe_one_click,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        EmailSender::upsert(
            $rows,
            ['email_account_id', 'email'],
            ['domain', 'name', 'unsubscribe_mailto', 'unsubscribe_url', 'unsubscribe_one_click', 'updated_at'],
        );

        return EmailSender::where('email_account_id', $account->id)
            ->whereIn('email', array_keys($newest))
            ->pluck('id', 'email')
            ->all();
    }

    /**
     * @param  list<int>  $senderIds
     */
    private function recount(array $senderIds): void
    {
        if ($senderIds === []) {
            return;
        }

        $alive = 'from email_messages_meta m where m.email_sender_id = email_senders.id and m.is_deleted = 0';

        EmailSender::whereIn('id', $senderIds)->update([
            'total_messages_received' => DB::raw("(select count(*) {$alive})"),
            'total_messages_unread' => DB::raw("(select count(*) {$alive} and m.labels like '%\"UNREAD\"%')"),
            'total_messages_promotional' => DB::raw("(select count(*) {$alive} and m.labels like '%\"CATEGORY_PROMOTIONS\"%')"),
            'last_email_received_at' => DB::raw("(select max(m.received_at) {$alive})"),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<MessageMetadata>  $batch
     */
    private function moveOldestBack(EmailAccount $account, array $batch): void
    {
        $oldest = min(array_map(fn (MessageMetadata $message) => $message->internalDate, $batch));

        if ($account->oldest_synced_at === null || $oldest->lessThan($account->oldest_synced_at)) {
            $account->forceFill(['oldest_synced_at' => $oldest])->save();
        }
    }

    private function toDatabase(CarbonImmutable $date): string
    {
        return $date->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
