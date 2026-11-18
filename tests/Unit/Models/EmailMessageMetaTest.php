<?php

namespace Tests\Unit\Models;

use App\Models\EmailAccount;
use App\Models\EmailMessageMeta;
use App\Models\EmailSender;
use App\Models\User;
use Tests\TestCase;

class EmailMessageMetaTest extends TestCase
{
    public function test_gmail_thread_url_attribute_generates_correct_deep_link(): void
    {
        $user = User::factory()->create();

        $account = EmailAccount::create([
            'user_id' => $user->id,
            'provider' => 'gmail',
            'account_email' => 'my.inbox@gmail.com',
            'access_token' => 'token_a',
            'refresh_token' => 'token_r',
        ]);

        $message = EmailMessageMeta::create([
            'email_account_id' => $account->id,
            'provider_message_id' => '18b2c45e89a01',
            'provider_thread_id' => '18b2c45e89a01_th',
            'sender_email' => 'orders@amazon.com.br',
            'sender_domain' => 'amazon.com.br',
            'subject' => 'Seu pedido foi enviado',
            'labels' => ['INBOX', 'CATEGORY_UPDATES', 'UNREAD'],
            'received_at' => now(),
        ]);

        $expectedUrl = 'https://mail.google.com/mail/u/?authuser=my.inbox%40gmail.com#all/18b2c45e89a01_th';
        $this->assertEquals($expectedUrl, $message->gmail_thread_url);
    }

    public function test_labels_are_properly_cast_to_and_from_json(): void
    {
        $user = User::factory()->create();

        $account = EmailAccount::create([
            'user_id' => $user->id,
            'provider' => 'gmail',
            'account_email' => 'labels.test@gmail.com',
            'access_token' => 'token_a',
            'refresh_token' => 'token_r',
        ]);

        $labels = ['INBOX', 'CATEGORY_PROMOTIONS', 'IMPORTANT'];

        $message = EmailMessageMeta::create([
            'email_account_id' => $account->id,
            'provider_message_id' => 'msg_label_1',
            'provider_thread_id' => 'th_label_1',
            'sender_email' => 'promo@store.com',
            'sender_domain' => 'store.com',
            'labels' => $labels,
            'received_at' => now(),
        ]);

        $reloaded = EmailMessageMeta::find($message->id);
        $this->assertIsArray($reloaded->labels);
        $this->assertEquals($labels, $reloaded->labels);
        $this->assertContains('CATEGORY_PROMOTIONS', $reloaded->labels);
    }

    public function test_message_meta_belongs_to_account_and_sender(): void
    {
        $user = User::factory()->create();

        $account = EmailAccount::create([
            'user_id' => $user->id,
            'provider' => 'gmail',
            'account_email' => 'belongsto@gmail.com',
            'access_token' => 'tok_a',
            'refresh_token' => 'tok_r',
        ]);

        $sender = EmailSender::create([
            'email_account_id' => $account->id,
            'email' => 'billing@stripe.com',
            'domain' => 'stripe.com',
        ]);

        $message = EmailMessageMeta::create([
            'email_account_id' => $account->id,
            'provider_message_id' => 'msg_stripe_1',
            'provider_thread_id' => 'th_stripe_1',
            'email_sender_id' => $sender->id,
            'sender_email' => 'billing@stripe.com',
            'sender_domain' => 'stripe.com',
            'received_at' => now(),
        ]);

        $this->assertEquals($account->id, $message->account->id);
        $this->assertEquals($sender->id, $message->sender->id);
    }
}
