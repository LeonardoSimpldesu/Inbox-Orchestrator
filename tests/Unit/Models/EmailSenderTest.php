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
}
