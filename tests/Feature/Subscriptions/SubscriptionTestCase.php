<?php

namespace Tests\Feature\Subscriptions;

use App\Models\EmailAccount;
use App\Models\EmailSender;
use App\Models\User;
use Tests\TestCase;

abstract class SubscriptionTestCase extends TestCase
{
    protected User $user;

    protected EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = EmailAccount::factory()->for($this->user)->create(['account_email' => 'me@gmail.com']);
    }

    protected function subscription(array $attributes = [], ?EmailAccount $account = null): EmailSender
    {
        static $n = 0;
        $n++;

        return EmailSender::create(array_merge([
            'email_account_id' => ($account ?? $this->account)->id,
            'email' => "news{$n}@site{$n}.com",
            'name' => "Site {$n}",
            'domain' => "site{$n}.com",
            'total_messages_received' => 10,
            'total_messages_unread' => 4,
            'last_email_received_at' => now()->subDay(),
            'unsubscribe_url' => "https://site{$n}.com/u?token=secret{$n}",
            'unsubscribe_one_click' => true,
            'unsubscribe_mailto' => "mailto:unsub@site{$n}.com",
        ], $attributes));
    }
}
