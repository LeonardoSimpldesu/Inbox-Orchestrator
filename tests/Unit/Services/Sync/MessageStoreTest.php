<?php

namespace Tests\Unit\Services\Sync;

use App\Models\EmailAccount;
use App\Models\EmailMessageMeta;
use App\Models\EmailSender;
use App\Services\Providers\MessageMetadata;
use App\Services\Sync\MessageStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeEmailProvider;
use Tests\TestCase;

class MessageStoreTest extends TestCase
{
    private EmailAccount $account;

    private MessageStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = EmailAccount::factory()->create();
        $this->store = new MessageStore;
    }

    private function message(string $id, string $from = 'News <news@site.com>', string $date = '2026-11-01 10:00:00', array $overrides = []): MessageMetadata
    {
        return FakeEmailProvider::message($id, $from, CarbonImmutable::parse($date), $overrides);
    }

    private function sender(string $email): EmailSender
    {
        return EmailSender::where('email_account_id', $this->account->id)->where('email', $email)->sole();
    }

    public function test_stores_messages_and_senders(): void
    {
        $this->store->store($this->account, [
            $this->message('m1', '"Site News" <News@Site.com>', overrides: ['subject' => 'Promo', 'labelIds' => ['INBOX', 'UNREAD', 'CATEGORY_PROMOTIONS']]),
        ]);

        $sender = $this->sender('news@site.com');
        $this->assertSame('Site News', $sender->name);
        $this->assertSame('site.com', $sender->domain);
        $this->assertSame('none', $sender->unsubscribe_status);

        $meta = EmailMessageMeta::sole();
        $this->assertSame($this->account->id, $meta->email_account_id);
        $this->assertSame('m1', $meta->provider_message_id);
        $this->assertSame('thread-m1', $meta->provider_thread_id);
        $this->assertSame($sender->id, $meta->email_sender_id);
        $this->assertSame('news@site.com', $meta->sender_email);
        $this->assertSame('site.com', $meta->sender_domain);
        $this->assertSame('Promo', $meta->subject);
        $this->assertSame(['INBOX', 'UNREAD', 'CATEGORY_PROMOTIONS'], $meta->labels);
        $this->assertSame('2026-11-01 10:00:00', $meta->received_at->format('Y-m-d H:i:s'));
        $this->assertFalse($meta->is_archived);
        $this->assertFalse($meta->is_deleted);
    }

    public function test_storing_same_batch_twice_is_idempotent(): void
    {
        $batch = [$this->message('m1'), $this->message('m2', date: '2026-11-02 10:00:00')];

        $this->store->store($this->account, $batch);
        $this->store->store($this->account, $batch);

        $this->assertSame(2, EmailMessageMeta::count());
        $this->assertSame(1, EmailSender::count());
        $this->assertSame(2, $this->sender('news@site.com')->total_messages_received);
        $this->assertSame(2, $this->sender('news@site.com')->total_messages_unread);
    }

    public function test_totals_and_unread_are_recomputed(): void
    {
        $this->store->store($this->account, [
            $this->message('m1', date: '2026-11-01 10:00:00'),
            $this->message('m2', date: '2026-11-03 10:00:00', overrides: ['labelIds' => ['INBOX']]),
        ]);
        $this->store->store($this->account, [$this->message('m1', date: '2026-11-01 10:00:00', overrides: ['labelIds' => ['INBOX']])]);

        $sender = $this->sender('news@site.com');
        $this->assertSame(2, $sender->total_messages_received);
        $this->assertSame(0, $sender->total_messages_unread);
        $this->assertSame('2026-11-03 10:00:00', $sender->last_email_received_at->format('Y-m-d H:i:s'));
    }

    public function test_trashed_or_spam_message_leaves_totals(): void
    {
        $this->store->store($this->account, [$this->message('m1'), $this->message('m2'), $this->message('m3')]);
        $this->store->store($this->account, [
            $this->message('m1', overrides: ['labelIds' => ['TRASH', 'UNREAD']]),
            $this->message('m2', overrides: ['labelIds' => ['SPAM']]),
        ]);

        $this->assertTrue(EmailMessageMeta::where('provider_message_id', 'm1')->sole()->is_deleted);
        $this->assertTrue(EmailMessageMeta::where('provider_message_id', 'm2')->sole()->is_deleted);
        $this->assertSame(1, $this->sender('news@site.com')->total_messages_received);
        $this->assertSame(1, $this->sender('news@site.com')->total_messages_unread);
    }

    public function test_promotional_messages_are_counted(): void
    {
        $this->store->store($this->account, [
            $this->message('p1', overrides: ['labelIds' => ['INBOX', 'CATEGORY_PROMOTIONS']]),
            $this->message('p2', overrides: ['labelIds' => ['CATEGORY_PROMOTIONS', 'TRASH']]),
            $this->message('u1', overrides: ['labelIds' => ['INBOX', 'CATEGORY_UPDATES']]),
        ]);

        $this->assertSame(1, $this->sender('news@site.com')->total_messages_promotional);
    }

    public function test_archived_flag_from_inbox_label(): void
    {
        $this->store->store($this->account, [$this->message('m1', overrides: ['labelIds' => ['CATEGORY_UPDATES']])]);

        $this->assertTrue(EmailMessageMeta::sole()->is_archived);
    }

    public function test_delete_removes_rows_and_recounts(): void
    {
        $this->store->store($this->account, [$this->message('m1'), $this->message('m2', 'Outro <outro@x.com>')]);

        $this->store->delete($this->account, ['m1', 'not-stored']);

        $this->assertSame(['m2'], EmailMessageMeta::pluck('provider_message_id')->all());
        $sender = $this->sender('news@site.com');
        $this->assertSame(0, $sender->total_messages_received);
        $this->assertSame(0, $sender->total_messages_unread);
        $this->assertNull($sender->last_email_received_at);
        $this->assertSame(1, $this->sender('outro@x.com')->total_messages_received);
    }

    public function test_delete_with_no_ids_does_nothing(): void
    {
        DB::enableQueryLog();
        $this->store->delete($this->account, []);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_unsubscribe_comes_from_newest_message(): void
    {
        $this->store->store($this->account, [
            $this->message('old', date: '2026-10-01 10:00:00', overrides: ['listUnsubscribe' => '<https://site.com/old>']),
            $this->message('new', '"Novo Nome" <news@site.com>', '2026-11-01 10:00:00', ['listUnsubscribe' => '<mailto:u@site.com>, <https://site.com/new>', 'listUnsubscribePost' => 'List-Unsubscribe=One-Click']),
        ]);

        $sender = $this->sender('news@site.com');
        $this->assertSame('https://site.com/new', $sender->unsubscribe_url);
        $this->assertSame('mailto:u@site.com', $sender->unsubscribe_mailto);
        $this->assertTrue($sender->unsubscribe_one_click);
        $this->assertSame('Novo Nome', $sender->name);
    }

    public function test_older_message_does_not_overwrite_unsubscribe(): void
    {
        $this->store->store($this->account, [$this->message('new', date: '2026-11-01 10:00:00', overrides: ['listUnsubscribe' => '<https://site.com/new>'])]);
        $this->store->store($this->account, [$this->message('old', 'Antigo <news@site.com>', '2025-01-01 10:00:00', ['listUnsubscribe' => '<https://site.com/old>'])]);

        $sender = $this->sender('news@site.com');
        $this->assertSame('https://site.com/new', $sender->unsubscribe_url);
        $this->assertSame('News', $sender->name);
        $this->assertSame(2, $sender->total_messages_received);
    }

    public function test_unsubscribe_status_is_preserved(): void
    {
        $this->store->store($this->account, [$this->message('m1')]);
        $this->sender('news@site.com')->update(['unsubscribe_status' => 'unsubscribed']);

        $this->store->store($this->account, [$this->message('m2', date: '2026-11-05 10:00:00')]);

        $this->assertSame('unsubscribed', $this->sender('news@site.com')->unsubscribe_status);
    }

    public function test_message_without_sender_has_no_sender_row(): void
    {
        $this->store->store($this->account, [$this->message('m1', 'Mailer-Daemon')]);

        $meta = EmailMessageMeta::sole();
        $this->assertNull($meta->email_sender_id);
        $this->assertSame('', $meta->sender_email);
        $this->assertSame('', $meta->sender_domain);
        $this->assertSame(0, EmailSender::count());
    }

    public function test_oldest_synced_at_only_moves_back(): void
    {
        $this->store->store($this->account, [$this->message('m1', date: '2026-11-01 10:00:00')]);
        $this->assertSame('2026-11-01 10:00:00', $this->account->fresh()->oldest_synced_at->format('Y-m-d H:i:s'));

        $this->store->store($this->account, [$this->message('m2', date: '2026-11-10 10:00:00')]);
        $this->assertSame('2026-11-01 10:00:00', $this->account->fresh()->oldest_synced_at->format('Y-m-d H:i:s'));

        $this->store->store($this->account, [$this->message('m3', date: '2025-01-01 10:00:00')]);
        $this->assertSame('2025-01-01 10:00:00', $this->account->fresh()->oldest_synced_at->format('Y-m-d H:i:s'));
    }

    public function test_storing_changes_does_not_move_oldest_back(): void
    {
        $this->store->store($this->account, [$this->message('m1', date: '2026-11-01 10:00:00')]);

        $this->store->store($this->account, [$this->message('ancient', date: '2020-01-01 10:00:00')], extendsCoverage: false);

        $this->assertSame('2026-11-01 10:00:00', $this->account->fresh()->oldest_synced_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, EmailMessageMeta::count());
    }

    public function test_empty_batch_does_nothing(): void
    {
        DB::enableQueryLog();
        $this->store->store($this->account, []);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_senders_are_isolated_per_account(): void
    {
        $other = EmailAccount::factory()->create();

        $this->store->store($this->account, [$this->message('m1')]);
        $this->store->store($other, [$this->message('m1')]);

        $this->assertSame(2, EmailMessageMeta::count());
        $this->assertSame(2, EmailSender::count());
        $this->assertSame(1, $this->sender('news@site.com')->total_messages_received);
    }

    public function test_batch_uses_constant_number_of_queries(): void
    {
        $count = function (EmailAccount $account, int $size): int {
            $batch = array_map(
                fn (int $i) => $this->message("m{$i}", "Sender {$i} <s{$i}@d{$i}.com>", '2026-11-01 10:00:00'),
                range(1, $size),
            );

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->store->store($account, $batch);
            $this->store->delete($account, array_map(fn (MessageMetadata $m) => $m->id, array_slice($batch, 0, max(1, intdiv($size, 2)))));
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $small = $count(EmailAccount::factory()->create(), 1);
        $large = $count(EmailAccount::factory()->create(), 100);

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(10, $large);
    }
}
