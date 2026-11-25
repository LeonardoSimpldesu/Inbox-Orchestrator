<?php

namespace Tests\Feature;

use Illuminate\Mail\Transport\ResendTransport;
use Tests\TestCase;

class MailTransportTest extends TestCase
{
    public function test_resend_mailer_is_available(): void
    {
        config(['services.resend.key' => 're_test']);

        $transport = app('mail.manager')->mailer('resend')->getSymfonyTransport();

        $this->assertInstanceOf(ResendTransport::class, $transport);
    }
}
