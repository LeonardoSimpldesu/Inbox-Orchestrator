<?php

namespace Tests\Unit\Services\Unsubscribe;

use App\Contracts\EmailProviderInterface;
use App\Models\EmailAccount;
use App\Models\EmailMessageMeta;
use App\Models\EmailSender;
use App\Services\Sync\MessageStore;
use App\Services\Unsubscribe\SenderMailCleaner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeEmailProvider;
use Tests\TestCase;

class SenderMailCleanerTest extends TestCase
{
    private FakeEmailProvider $provider;

    private EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeEmailProvider;
        $this->app->instance(EmailProviderInterface::class, $this->provider);
        $this->account = EmailAccount::factory()->create();
    }

    /**
     * Puts $count messages from the sender in the Gmail mailbox; the first $stored are also synced locally.
     */
    private function seedSender(int $count, int $stored, string $from = 'News <news@site.com>'): EmailSender
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $messages[] = FakeEmailProvider::message("m{$i}", $from, CarbonImmutable::parse('2026-11-01')->subMinutes($i), ['labelIds' => ['INBOX', 'UNREAD', 'CATEGORY_PROMOTIONS']]);
        }
        $this->provider->addMessages(...$messages);
        (new MessageStore)->store($this->account, array_slice($messages, 0, $stored));

        return EmailSender::where('email', str($from)->between('<', '>')->toString())->sole();
    }

    private function cleaner(): SenderMailCleaner
    {
        return $this->app->make(SenderMailCleaner::class);
    }

    public function test_archive_removes_inbox_for_all_sender_mail(): void
    {
        $sender = $this->seedSender(5, 3);
        $this->provider->addMessages(FakeEmailProvider::message('other', 'Other <other@x.com>', CarbonImmutable::parse('2026-11-01')));

        $this->cleaner()->clean($sender, 'archive');

        $this->assertSame('from:news@site.com', $this->provider->listCalls[0]['query']);
        $this->assertCount(1, $this->provider->modified);
        $this->assertEqualsCanonicalizing(['m0', 'm1', 'm2', 'm3', 'm4'], $this->provider->modified[0]['ids']);
        $this->assertSame([], $this->provider->modified[0]['add']);
        $this->assertSame(['INBOX'], $this->provider->modified[0]['remove']);
    }

    public function test_trash_adds_trash_label(): void
    {
        $sender = $this->seedSender(2, 2);

        $this->cleaner()->clean($sender, 'trash');

        $this->assertSame(['TRASH'], $this->provider->modified[0]['add']);
        $this->assertSame([], $this->provider->modified[0]['remove']);
    }

    public function test_keep_does_nothing(): void
    {
        $sender = $this->seedSender(2, 2);

        $this->cleaner()->clean($sender, 'keep');

        $this->assertSame([], $this->provider->listCalls);
        $this->assertSame([], $this->provider->modified);
    }

    public function test_updates_local_flags_and_totals(): void
    {
        $sender = $this->seedSender(3, 3);

        $this->cleaner()->clean($sender, 'archive');
        $this->assertSame(3, EmailMessageMeta::where('is_archived', true)->count());
        $this->assertSame(['UNREAD', 'CATEGORY_PROMOTIONS'], EmailMessageMeta::where('provider_message_id', 'm0')->sole()->labels);
        $this->assertSame(3, $sender->fresh()->total_messages_received);

        $this->cleaner()->clean($sender->fresh(), 'trash');
        $this->assertSame(3, EmailMessageMeta::where('is_deleted', true)->count());
        $fresh = $sender->fresh();
        $this->assertSame(0, $fresh->total_messages_received);
        $this->assertSame(0, $fresh->total_messages_promotional);
    }

    public function test_cleanup_uses_constant_queries(): void
    {
        $count = function (int $stored, string $from): int {
            $sender = $this->seedSender($stored, $stored, $from);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->cleaner()->clean($sender, 'trash');
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(10, 'A <a@a.com>'), $count(300, 'B <b@b.com>'));
    }
}
