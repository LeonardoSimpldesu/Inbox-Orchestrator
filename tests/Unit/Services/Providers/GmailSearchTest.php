<?php

namespace Tests\Unit\Services\Providers;

use App\Services\Providers\GmailSearch;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GmailSearchTest extends TestCase
{
    public function test_from_quotes_the_address(): void
    {
        $this->assertSame('from:"news+promo@site.com"', GmailSearch::from('news+promo@site.com'));
    }

    public function test_from_refuses_anything_that_could_leave_the_quotes(): void
    {
        foreach (['a"b@x.com', 'a b@x.com', "a\tb@x.com", "a\nb@x.com"] as $address) {
            try {
                GmailSearch::from($address);
                $this->fail("Accepted {$address}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_protected_mail_is_starred_sent_and_drafts(): void
    {
        $this->assertSame('-is:starred -in:sent -in:drafts', GmailSearch::PROTECTED);
    }
}
