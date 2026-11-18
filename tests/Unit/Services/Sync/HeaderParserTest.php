<?php

namespace Tests\Unit\Services\Sync;

use App\Services\Sync\HeaderParser;
use PHPUnit\Framework\TestCase;

class HeaderParserTest extends TestCase
{
    public function test_sender_with_quoted_name(): void
    {
        $sender = HeaderParser::sender('"TechCrunch Daily" <Newsletter@TechCrunch.com>');

        $this->assertSame('newsletter@techcrunch.com', $sender?->email);
        $this->assertSame('techcrunch.com', $sender?->domain);
        $this->assertSame('TechCrunch Daily', $sender?->name);
    }

    public function test_sender_without_name(): void
    {
        $sender = HeaderParser::sender('alerts@bank.com.br');

        $this->assertSame('alerts@bank.com.br', $sender?->email);
        $this->assertSame('bank.com.br', $sender?->domain);
        $this->assertNull($sender?->name);

        $this->assertNull(HeaderParser::sender('<only@angle.com>')?->name);
    }

    public function test_sender_lowercases_and_takes_last_at_domain(): void
    {
        $sender = HeaderParser::sender('Loja <"weird@local"@Shop.Example.COM>');

        $this->assertSame('shop.example.com', $sender?->domain);
        $this->assertSame('Loja', $sender?->name);
    }

    public function test_sender_without_address_returns_null(): void
    {
        $this->assertNull(HeaderParser::sender(null));
        $this->assertNull(HeaderParser::sender(''));
        $this->assertNull(HeaderParser::sender('Mailer-Daemon'));
        $this->assertNull(HeaderParser::sender('Nome <sem-arroba>'));
    }

    public function test_unsubscribe_mailto_and_https(): void
    {
        $result = HeaderParser::unsubscribe('<mailto:unsub@news.com?subject=unsubscribe>, <https://news.com/u/123>', null);

        $this->assertSame('mailto:unsub@news.com?subject=unsubscribe', $result->mailto);
        $this->assertSame('https://news.com/u/123', $result->url);
        $this->assertFalse($result->oneClick);
    }

    public function test_unsubscribe_ignores_http(): void
    {
        $result = HeaderParser::unsubscribe('<http://news.com/u/1>', 'List-Unsubscribe=One-Click');

        $this->assertNull($result->url);
        $this->assertNull($result->mailto);
        $this->assertFalse($result->oneClick);
    }

    public function test_one_click_requires_https_and_post_header(): void
    {
        $this->assertTrue(HeaderParser::unsubscribe('<https://news.com/u/1>', 'List-Unsubscribe=One-Click')->oneClick);
        $this->assertFalse(HeaderParser::unsubscribe('<mailto:a@b.com>', 'List-Unsubscribe=One-Click')->oneClick);
    }

    public function test_empty_unsubscribe(): void
    {
        $result = HeaderParser::unsubscribe(null, null);

        $this->assertNull($result->mailto);
        $this->assertNull($result->url);
        $this->assertFalse($result->oneClick);
        $this->assertNull(HeaderParser::unsubscribe('', '')->url);
    }

    public function test_subject_is_truncated_to_255(): void
    {
        $this->assertNull(HeaderParser::subject(null));
        $this->assertSame('Olá', HeaderParser::subject('Olá'));
        $this->assertSame(255, mb_strlen((string) HeaderParser::subject(str_repeat('é', 300))));
    }
}
