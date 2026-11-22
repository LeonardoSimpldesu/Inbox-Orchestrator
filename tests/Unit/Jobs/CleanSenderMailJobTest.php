<?php

namespace Tests\Unit\Jobs;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\ReauthorizationRequired;
use App\Jobs\CleanSenderMailJob;
use App\Models\EmailAccount;
use App\Models\EmailSender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\FakeEmailProvider;
use Tests\TestCase;

class CleanSenderMailJobTest extends TestCase
{
    private FakeEmailProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeEmailProvider;
        $this->app->instance(EmailProviderInterface::class, $this->provider);
        $this->provider->addMessages(FakeEmailProvider::message('m1', 'A <a@x.com>', CarbonImmutable::parse('2026-11-01')));
    }

    private function sender(?EmailAccount $account = null): EmailSender
    {
        return EmailSender::create(['email_account_id' => ($account ?? EmailAccount::factory()->create())->id, 'email' => 'a@x.com', 'domain' => 'x.com', 'total_messages_received' => 1]);
    }

    public function test_cleans_with_sender_mail_cleaner(): void
    {
        $sender = $this->sender();

        dispatch_sync(new CleanSenderMailJob($sender->id, $sender->email_account_id, 'trash'));

        $this->assertSame(['m1'], $this->provider->modified[0]['ids']);
        $this->assertSame(['TRASH'], $this->provider->modified[0]['add']);
    }

    public function test_skips_unsyncable_and_missing(): void
    {
        $sender = $this->sender(EmailAccount::factory()->needsReauth()->create());

        dispatch_sync(new CleanSenderMailJob($sender->id, $sender->email_account_id, 'archive'));
        dispatch_sync(new CleanSenderMailJob(999999, 1, 'archive'));

        $this->assertSame([], $this->provider->modified);
    }

    public function test_errors_are_logged_with_codes(): void
    {
        Log::spy();
        $sender = $this->sender();
        $this->provider->failNext['listAllMessageIds'] = new ReauthorizationRequired('invalid_grant');

        dispatch_sync(new CleanSenderMailJob($sender->id, $sender->email_account_id, 'archive'));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m, array $c) => $c === ['sender_id' => $sender->id, 'error' => 'reauth_required']);
    }

    public function test_lock_and_account_lock(): void
    {
        $job = new CleanSenderMailJob(4, 7, 'archive');

        $this->assertSame([10, 1, 1500], [$job->tries, $job->maxExceptions, $job->timeout]);
        [$own, $account] = $job->middleware();
        $this->assertSame(['sender-clean-4', null], [$own->key, $own->releaseAfter]);
        $this->assertSame(['sync-account-7', 60], [$account->key, $account->releaseAfter]);
    }
}
