<?php

namespace App\Services\Unsubscribe;

use App\Contracts\EmailProviderInterface;
use App\Models\EmailAccount;

class MailtoUnsubscriber
{
    public function __construct(
        private readonly EmailProviderInterface $provider,
        private readonly MailtoParser $parser,
    ) {}

    public function unsubscribe(EmailAccount $account, string $mailto): void
    {
        $parsed = $this->parser->parse($mailto);

        $this->provider->sendMessage($account, $parsed->to, $parsed->subject, $parsed->body);
    }
}
