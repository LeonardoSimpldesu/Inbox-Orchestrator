<?php

namespace Tests\Unit\Jobs;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use App\Jobs\UnsubscribeSenderJob;
use App\Models\EmailAccount;
use App\Models\EmailSender;
use App\Models\User;
use App\Services\Unsubscribe\HostResolver;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\FakeEmailProvider;
use Tests\TestCase;

class UnsubscribeSenderJobTest extends TestCase
{
    private FakeEmailProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->provider = new FakeEmailProvider;
        $this->app->instance(EmailProviderInterface::class, $this->provider);
        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    private function sender(array $attributes = [], ?EmailAccount $account = null): EmailSender
    {
        $account ??= EmailAccount::factory()->create();
        $this->provider->addMessages(FakeEmailProvider::message('m1', 'News <news@site.com>', CarbonImmutable::parse('2026-11-01')));

        return EmailSender::create(array_merge([
            'email_account_id' => $account->id,
            'email' => 'news@site.com',
            'domain' => 'site.com',
            'total_messages_received' => 1,
            'unsubscribe_url' => 'https://site.com/u?token=secret',
            'unsubscribe_one_click' => true,
            'unsubscribe_mailto' => 'mailto:unsub@site.com?subject=stop',
            'unsubscribe_status' => EmailSender::STATUS_PROCESSING,
        ], $attributes));
    }

    private function runJob(EmailSender $sender, string $method, string $cleanup = 'keep'): EmailSender
    {
        dispatch_sync(new UnsubscribeSenderJob($sender->id, $method, $cleanup));

        return $sender->fresh();
    }

    public function test_one_click_success_marks_requested_and_cleans(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $sender = $this->runJob($this->sender(), 'one_click', 'archive');

        $this->assertSame(EmailSender::STATUS_REQUESTED, $sender->unsubscribe_status);
        $this->assertSame('one_click', $sender->unsubscribe_method);
        $this->assertSame(now()->timestamp, $sender->unsubscribe_requested_at->timestamp);
        $this->assertNull($sender->unsubscribe_error);
        $this->assertSame(['INBOX'], $this->provider->modified[0]['remove']);
    }

    public function test_mailto_success_marks_requested(): void
    {
        $sender = $this->runJob($this->sender(['unsubscribe_error' => 'timeout']), 'mailto');

        $this->assertSame(EmailSender::STATUS_REQUESTED, $sender->unsubscribe_status);
        $this->assertSame('mailto', $sender->unsubscribe_method);
        $this->assertNull($sender->unsubscribe_error);
        $this->assertSame('unsub@site.com', $this->provider->sent[0]['to']);
        $this->assertSame([], $this->provider->modified);
    }

    public function test_failure_marks_failed_and_skips_cleanup(): void
    {
        Http::fake(['*' => Http::response('', 410)]);

        $sender = $this->runJob($this->sender(), 'one_click', 'trash');

        $this->assertSame(EmailSender::STATUS_FAILED, $sender->unsubscribe_status);
        $this->assertSame('http_410', $sender->unsubscribe_error);
        $this->assertNull($sender->unsubscribe_requested_at);
        $this->assertSame([], $this->provider->modified);
    }

    public function test_page_and_rule_methods_only_clean(): void
    {
        Http::fake();
        $page = $this->runJob($this->sender(['unsubscribe_status' => EmailSender::STATUS_REQUESTED, 'unsubscribe_method' => 'page']), 'page', 'trash');

        $this->assertSame(EmailSender::STATUS_REQUESTED, $page->unsubscribe_status);
        $this->assertSame(['TRASH'], $this->provider->modified[0]['add']);
        $this->assertSame([], $this->provider->sent);
        Http::assertNothingSent();
    }

    public function test_rate_limit_and_reauth_map_to_codes(): void
    {
        $this->provider->failNext['sendMessage'] = new ProviderUnavailable('rate_limited');
        $this->assertSame('rate_limited', $this->runJob($this->sender(), 'mailto')->unsubscribe_error);

        EmailSender::query()->delete();
        $this->provider->failNext['sendMessage'] = new ReauthorizationRequired('invalid_grant');
        $this->assertSame('reauth_required', $this->runJob($this->sender(), 'mailto')->unsubscribe_error);
    }

    public function test_cleanup_failure_keeps_request_and_records_error(): void
    {
        $this->provider->failNext['listAllMessageIds'] = new ProviderUnavailable('http_500');

        $sender = $this->runJob($this->sender(), 'mailto', 'archive');

        $this->assertSame(EmailSender::STATUS_REQUESTED, $sender->unsubscribe_status);
        $this->assertSame('cleanup_http_500', $sender->unsubscribe_error);
    }

    public function test_skips_unsyncable_accounts(): void
    {
        $reauth = $this->runJob($this->sender([], EmailAccount::factory()->needsReauth()->create()), 'mailto');
        $this->assertSame(EmailSender::STATUS_FAILED, $reauth->unsubscribe_status);
        $this->assertSame('reauth_required', $reauth->unsubscribe_error);

        EmailSender::query()->delete();
        $disabled = $this->runJob($this->sender([], EmailAccount::factory()->for(User::factory()->state(['disabled_at' => now()]))->create()), 'mailto');
        $this->assertSame('account_unavailable', $disabled->unsubscribe_error);

        EmailSender::query()->delete();
        $disconnected = $this->sender([], EmailAccount::factory()->disconnected()->create());
        $this->assertSame('account_unavailable', $this->runJob($disconnected, 'mailto')->unsubscribe_error);

        $this->assertSame([], $this->provider->sent);
        dispatch_sync(new UnsubscribeSenderJob(999999, 'mailto', 'keep'));
    }

    public function test_logs_only_sender_id_and_code(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response('', 500)]);
        $sender = $this->sender();

        $this->runJob($sender, 'one_click');

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($sender) {
            $this->assertSame(['sender_id' => $sender->id, 'error' => 'http_500'], $context);
            $this->assertStringNotContainsString('secret', $message.json_encode($context));

            return true;
        });
    }

    public function test_job_lock_and_queue(): void
    {
        $job = new UnsubscribeSenderJob(42, 'mailto', 'keep');

        $this->assertSame(1, $job->tries);
        $this->assertNull($job->queue);
        [$lock] = $job->middleware();
        $this->assertInstanceOf(WithoutOverlapping::class, $lock);
        $this->assertSame('unsubscribe-sender-42', $lock->key);
        $this->assertNull($lock->releaseAfter);
    }
}
