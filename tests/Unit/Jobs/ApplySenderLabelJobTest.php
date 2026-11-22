<?php

namespace Tests\Unit\Jobs;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\ProviderUnavailable;
use App\Jobs\ApplySenderLabelJob;
use App\Models\EmailAccount;
use App\Models\EmailMessageMeta;
use App\Models\EmailSender;
use App\Models\User;
use App\Services\Sync\MessageStore;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\FakeEmailProvider;
use Tests\TestCase;

class ApplySenderLabelJobTest extends TestCase
{
    private FakeEmailProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeEmailProvider;
        $this->app->instance(EmailProviderInterface::class, $this->provider);
    }

    private function senderWithMail(?EmailAccount $account = null, array $labels = ['INBOX', 'Label_old']): EmailSender
    {
        $account ??= EmailAccount::factory()->create();
        $messages = [
            FakeEmailProvider::message('m1', 'Banco <a@banco.com>', CarbonImmutable::parse('2026-11-01'), ['labelIds' => $labels]),
            FakeEmailProvider::message('m2', 'Banco <a@banco.com>', CarbonImmutable::parse('2026-11-02'), ['labelIds' => $labels]),
            FakeEmailProvider::message('x1', 'Outro <o@x.com>', CarbonImmutable::parse('2026-11-02'), ['labelIds' => ['INBOX']]),
        ];
        $this->provider->addMessages(...$messages);
        (new MessageStore)->store($account, $messages);

        return EmailSender::where('email', 'a@banco.com')->where('email_account_id', $account->id)->sole()->forceFill(['label_applying_at' => now()]);
    }

    public function test_adds_new_and_removes_old_label_on_all_sender_mail(): void
    {
        $sender = tap($this->senderWithMail())->save();

        dispatch_sync(new ApplySenderLabelJob($sender->id, $sender->email_account_id, 'Label_new', 'Label_old'));

        $this->assertEqualsCanonicalizing(['m1', 'm2'], $this->provider->modified[0]['ids']);
        $this->assertSame(['Label_new'], $this->provider->modified[0]['add']);
        $this->assertSame(['Label_old'], $this->provider->modified[0]['remove']);
        $this->assertSame(['INBOX', 'Label_new'], EmailMessageMeta::where('provider_message_id', 'm1')->sole()->labels);
        $this->assertNull($sender->fresh()->label_applying_at);
    }

    public function test_removing_category_only_removes(): void
    {
        $sender = tap($this->senderWithMail())->save();

        dispatch_sync(new ApplySenderLabelJob($sender->id, $sender->email_account_id, null, 'Label_old'));

        $this->assertSame([], $this->provider->modified[0]['add']);
        $this->assertSame(['Label_old'], $this->provider->modified[0]['remove']);
    }

    public function test_clears_applying_flag_on_error_and_failed(): void
    {
        Log::spy();
        $sender = tap($this->senderWithMail())->save();
        $this->provider->failNext['listAllMessageIds'] = new ProviderUnavailable('rate_limited');

        dispatch_sync(new ApplySenderLabelJob($sender->id, $sender->email_account_id, 'Label_new', null));

        $this->assertNull($sender->fresh()->label_applying_at);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m, array $c) => $c === ['sender_id' => $sender->id, 'error' => 'rate_limited']);

        $sender->forceFill(['label_applying_at' => now()])->save();
        (new ApplySenderLabelJob($sender->id, $sender->email_account_id, 'Label_new', null))->failed(new \RuntimeException('x'));
        $this->assertNull($sender->fresh()->label_applying_at);
        (new ApplySenderLabelJob(999999, 1, 'Label_new', null))->failed(new \RuntimeException('x'));
    }

    public function test_skips_unsyncable_accounts(): void
    {
        $reauth = tap($this->senderWithMail(EmailAccount::factory()->needsReauth()->create()))->save();
        dispatch_sync(new ApplySenderLabelJob($reauth->id, $reauth->email_account_id, 'Label_new', null));

        $disabled = tap($this->senderWithMail(EmailAccount::factory()->for(User::factory()->state(['disabled_at' => now()]))->create()))->save();
        dispatch_sync(new ApplySenderLabelJob($disabled->id, $disabled->email_account_id, 'Label_new', null));

        $gone = tap($this->senderWithMail(EmailAccount::factory()->disconnected()->create()))->save();
        dispatch_sync(new ApplySenderLabelJob($gone->id, $gone->email_account_id, 'Label_new', null));
        dispatch_sync(new ApplySenderLabelJob(999999, 1, 'Label_new', null));

        $this->assertSame([], $this->provider->modified);
        $this->assertNull($reauth->fresh()->label_applying_at);
        $this->assertNull($gone->fresh()->label_applying_at);
    }

    public function test_lock_and_account_lock(): void
    {
        $job = new ApplySenderLabelJob(4, 7, 'Label_1', null);

        $this->assertSame([10, 1, 1500], [$job->tries, $job->maxExceptions, $job->timeout]);
        [$own, $account] = $job->middleware();
        $this->assertInstanceOf(WithoutOverlapping::class, $own);
        $this->assertSame(['sender-label-4', null, 1600], [$own->key, $own->releaseAfter, $own->expiresAfter]);
        $this->assertSame(['sync-account-7', 60, 1600], [$account->key, $account->releaseAfter, $account->expiresAfter]);
    }
}
