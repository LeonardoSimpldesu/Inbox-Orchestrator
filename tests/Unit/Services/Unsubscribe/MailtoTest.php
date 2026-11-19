<?php

namespace Tests\Unit\Services\Unsubscribe;

use App\Contracts\EmailProviderInterface;
use App\Models\EmailAccount;
use App\Services\Unsubscribe\MailtoParser;
use App\Services\Unsubscribe\MailtoUnsubscriber;
use App\Services\Unsubscribe\UnsubscribeFailed;
use Tests\Fakes\FakeEmailProvider;
use Tests\TestCase;

class MailtoTest extends TestCase
{
    private function assertInvalid(string $mailto): void
    {
        try {
            (new MailtoParser)->parse($mailto);
            $this->fail("Expected invalid_mailto for {$mailto}");
        } catch (UnsubscribeFailed $e) {
            $this->assertSame('invalid_mailto', $e->getMessage());
        }
    }

    public function test_mailto_defaults_and_params(): void
    {
        $plain = (new MailtoParser)->parse('mailto:Unsub@News.com');
        $this->assertSame('Unsub@News.com', $plain->to);
        $this->assertSame('unsubscribe', $plain->subject);
        $this->assertSame('unsubscribe', $plain->body);

        $full = (new MailtoParser)->parse('MAILTO:a%2Bb@news.com,other@news.com?Subject=Remove%20me&body=List%3A%20123%0D%0Abye');
        $this->assertSame('a+b@news.com', $full->to);
        $this->assertSame('Remove me', $full->subject);
        $this->assertSame("List: 123\r\nbye", $full->body);
    }

    public function test_mailto_rejects_header_injection(): void
    {
        $this->assertInvalid('mailto:unsub@news.com%0D%0ABcc:victim@x.com');
        $this->assertInvalid('mailto:unsub@news.com?subject=hi%0D%0ABcc:%20victim@x.com');
        $this->assertInvalid("mailto:unsub@news.com?subject=hi\nBcc: v@x.com");
    }

    public function test_mailto_invalid_address(): void
    {
        $this->assertInvalid('https://news.com/u');
        $this->assertInvalid('mailto:');
        $this->assertInvalid('mailto:not-an-address');
    }

    public function test_long_subject_and_body_are_truncated(): void
    {
        $parsed = (new MailtoParser)->parse('mailto:u@n.com?subject='.str_repeat('a', 500).'&body='.str_repeat('b', 5000));

        $this->assertSame(200, strlen($parsed->subject));
        $this->assertSame(1000, strlen($parsed->body));
    }

    public function test_mailto_unsubscriber_sends_via_provider(): void
    {
        $provider = new FakeEmailProvider;
        $this->app->instance(EmailProviderInterface::class, $provider);
        $account = EmailAccount::factory()->create();

        $this->app->make(MailtoUnsubscriber::class)->unsubscribe($account, 'mailto:unsub@news.com?subject=stop');

        $this->assertSame([['account_id' => $account->id, 'to' => 'unsub@news.com', 'subject' => 'stop', 'body' => 'unsubscribe']], $provider->sent);
    }
}
