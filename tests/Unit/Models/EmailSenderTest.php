<?php

namespace Tests\Unit\Models;

use App\Models\EmailAccount;
use App\Models\EmailMessageMeta;
use App\Models\EmailSender;
use App\Models\User;
use Tests\TestCase;

class EmailSenderTest extends TestCase
{
    public function test_email_sender_belongs_to_account_and_has_messages_meta(): void
    {
        $user = User::factory()->create();

        $account = EmailAccount::create([
            'user_id' => $user->id,
            'provider' => 'gmail',
            'account_email' => 'sender_test@gmail.com',
            'access_token' => 'tok_a',
            'refresh_token' => 'tok_r',
        ]);

        $sender = EmailSender::create([
            'email_account_id' => $account->id,
            'email' => 'news@medium.com',
            'domain' => 'medium.com',
            'total_messages_received' => 5,
        ]);

        EmailMessageMeta::create([
            'email_account_id' => $account->id,
            'provider_message_id' => 'medium_msg_1',
            'provider_thread_id' => 'medium_th_1',
            'email_sender_id' => $sender->id,
            'sender_email' => 'news@medium.com',
            'sender_domain' => 'medium.com',
            'received_at' => now(),
        ]);

        // Test relations
        $this->assertEquals($account->id, $sender->account->id);
        $this->assertCount(1, $sender->messagesMeta);
        $this->assertEquals('medium_msg_1', $sender->messagesMeta->first()->provider_message_id);
    }

    private function senderOn(EmailAccount $account, string $email, array $attributes = []): EmailSender
    {
        return EmailSender::create(array_merge([
            'email_account_id' => $account->id,
            'email' => $email,
            'domain' => str($email)->after('@')->toString(),
            'total_messages_received' => 3,
        ], $attributes));
    }

    public function test_subscription_scope_criteria(): void
    {
        $account = EmailAccount::factory()->create();
        $this->senderOn($account, 'oneclick@a.com', ['unsubscribe_url' => 'https://a.com/u', 'unsubscribe_one_click' => true]);
        $this->senderOn($account, 'mailto@b.com', ['unsubscribe_mailto' => 'mailto:u@b.com']);
        $this->senderOn($account, 'promo@c.com', ['total_messages_promotional' => 2]);
        $this->senderOn($account, 'friend@d.com');
        $this->senderOn($account, 'gone@e.com', ['unsubscribe_url' => 'https://e.com/u', 'total_messages_received' => 0]);

        $this->assertSame(
            ['mailto@b.com', 'oneclick@a.com', 'promo@c.com'],
            EmailSender::subscriptions()->orderBy('email')->pluck('email')->all(),
        );
    }

    public function test_pending_and_handled_scopes(): void
    {
        $account = EmailAccount::factory()->create();
        foreach (EmailSender::STATUSES as $status) {
            $this->senderOn($account, "{$status}@x.com", ['unsubscribe_mailto' => 'mailto:u@x.com', 'unsubscribe_status' => $status]);
        }

        $this->assertSame(['failed@x.com', 'none@x.com', 'processing@x.com'], EmailSender::pendingSubscriptions()->orderBy('email')->pluck('email')->all());
        $this->assertSame(['blocked@x.com', 'requested@x.com'], EmailSender::handledSubscriptions()->orderBy('email')->pluck('email')->all());
    }

    public function test_unsubscribe_options(): void
    {
        $account = EmailAccount::factory()->create();

        $this->assertSame(
            ['one_click' => true, 'mailto' => true, 'page' => true],
            $this->senderOn($account, 'a@a.com', ['unsubscribe_url' => 'https://a.com/u', 'unsubscribe_one_click' => true, 'unsubscribe_mailto' => 'mailto:u@a.com'])->unsubscribeOptions(),
        );
        $this->assertSame(
            ['one_click' => false, 'mailto' => false, 'page' => true],
            $this->senderOn($account, 'b@b.com', ['unsubscribe_url' => 'https://b.com/u'])->unsubscribeOptions(),
        );
        $this->assertSame(
            ['one_click' => false, 'mailto' => false, 'page' => false],
            $this->senderOn($account, 'c@c.com', ['total_messages_promotional' => 1])->unsubscribeOptions(),
        );
    }

    public function test_non_subscriptions_scope(): void
    {
        $account = EmailAccount::factory()->create();
        $this->senderOn($account, 'news@a.com', ['unsubscribe_mailto' => 'mailto:u@a.com']);
        $this->senderOn($account, 'promo@b.com', ['total_messages_promotional' => 1]);
        $this->senderOn($account, 'maria@gmail.com');
        $this->senderOn($account, 'bank@c.com');

        $this->assertSame(['bank@c.com', 'maria@gmail.com'], EmailSender::nonSubscriptions()->orderBy('email')->pluck('email')->all());
    }

    public function test_search_scope_escapes_wildcards(): void
    {
        $account = EmailAccount::factory()->create();
        $this->senderOn($account, 'maria@gmail.com', ['name' => 'Maria Souza']);
        $this->senderOn($account, 'bank@c.com', ['name' => '100% Banco']);

        $this->assertSame(['maria@gmail.com'], EmailSender::search('SOUZA')->pluck('email')->all());
        $this->assertSame(['bank@c.com'], EmailSender::search('100%')->pluck('email')->all());
        $this->assertSame([], EmailSender::search('_')->pluck('email')->all());
    }
}
