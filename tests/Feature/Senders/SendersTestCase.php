<?php

namespace Tests\Feature\Senders;

use App\Contracts\EmailProviderInterface;
use App\Models\EmailAccount;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use App\Models\User;
use Tests\Fakes\FakeEmailProvider;
use Tests\TestCase;

abstract class SendersTestCase extends TestCase
{
    protected FakeEmailProvider $provider;

    protected User $user;

    protected EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeEmailProvider;
        $this->provider->labels = [['id' => 'Label_1', 'name' => 'Banco'], ['id' => 'Label_2', 'name' => 'Família']];
        $this->app->instance(EmailProviderInterface::class, $this->provider);
        $this->user = User::factory()->create();
        $this->account = EmailAccount::factory()->for($this->user)->create(['account_email' => 'me@gmail.com']);
    }

    protected function sender(array $attributes = [], ?EmailAccount $account = null): EmailSender
    {
        static $n = 0;
        $n++;

        return EmailSender::create(array_merge([
            'email_account_id' => ($account ?? $this->account)->id,
            'email' => "pessoa{$n}@site{$n}.com",
            'name' => "Pessoa {$n}",
            'domain' => "site{$n}.com",
            'total_messages_received' => 10,
            'total_messages_unread' => 2,
            'last_email_received_at' => now()->subDay(),
        ], $attributes));
    }

    protected function categorize(EmailSender $sender, string $label): RetentionRule
    {
        return RetentionRule::create(['email_account_id' => $sender->email_account_id, 'name' => 'c', 'filter_type' => 'sender', 'filter_value' => $sender->email, 'retention_days' => 0, 'action' => 'add_label', 'target_label' => $label, 'is_active' => true]);
    }
}
