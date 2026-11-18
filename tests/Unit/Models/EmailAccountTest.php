<?php

namespace Tests\Unit\Models;

use App\Models\EmailAccount;
use App\Models\EmailMessageMeta;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmailAccountTest extends TestCase
{
    public function test_oauth_tokens_are_encrypted_at_rest_in_database(): void
    {
        $user = User::factory()->create();

        $account = EmailAccount::create([
            'user_id' => $user->id,
            'provider' => 'gmail',
            'account_email' => 'secure@gmail.com',
            'access_token' => 'plain_text_access_token_12345',
            'refresh_token' => 'plain_text_refresh_token_67890',
        ]);

        // Direct database query without Eloquent cast to inspect raw stored bytes
        $rawRow = DB::table('email_accounts')->where('id', $account->id)->first();

        $this->assertNotEquals('plain_text_access_token_12345', $rawRow->access_token);
        $this->assertNotEquals('plain_text_refresh_token_67890', $rawRow->refresh_token);

        // Through Eloquent, decryption is seamless
        $reloaded = EmailAccount::find($account->id);
        $this->assertEquals('plain_text_access_token_12345', $reloaded->access_token);
        $this->assertEquals('plain_text_refresh_token_67890', $reloaded->refresh_token);
    }

    public function test_deleting_account_cascades_and_removes_senders_and_rules(): void
    {
        $user = User::factory()->create();

        $account = EmailAccount::create([
            'user_id' => $user->id,
            'provider' => 'gmail',
            'account_email' => 'to_delete@gmail.com',
            'access_token' => 'token_a',
            'refresh_token' => 'token_r',
        ]);

        $sender = EmailSender::create([
            'email_account_id' => $account->id,
            'email' => 'spam@promo.com',
            'domain' => 'promo.com',
        ]);

        EmailMessageMeta::create([
            'email_account_id' => $account->id,
            'provider_message_id' => 'msg_101',
            'provider_thread_id' => 'thread_101',
            'email_sender_id' => $sender->id,
            'sender_email' => 'spam@promo.com',
            'sender_domain' => 'promo.com',
            'received_at' => now(),
        ]);

        RetentionRule::create([
            'email_account_id' => $account->id,
            'name' => 'Clean spam',
            'filter_type' => 'domain',
            'filter_value' => 'promo.com',
            'retention_days' => 7,
            'action' => 'trash',
        ]);

        $this->assertDatabaseHas('email_senders', ['id' => $sender->id]);
        $this->assertDatabaseHas('email_messages_meta', ['provider_message_id' => 'msg_101']);

        // Delete account
        $account->delete();

        // All related children must be deleted automatically by cascade
        $this->assertDatabaseMissing('email_accounts', ['id' => $account->id]);
        $this->assertDatabaseMissing('email_senders', ['email_account_id' => $account->id]);
        $this->assertDatabaseMissing('email_messages_meta', ['email_account_id' => $account->id]);
        $this->assertDatabaseMissing('retention_rules', ['email_account_id' => $account->id]);
    }

    public function test_email_account_relations_return_expected_instances(): void
    {
        $user = User::factory()->create();

        $account = EmailAccount::create([
            'user_id' => $user->id,
            'provider' => 'gmail',
            'account_email' => 'relations@gmail.com',
            'access_token' => 'tok_a',
            'refresh_token' => 'tok_r',
        ]);

        EmailSender::create([
            'email_account_id' => $account->id,
            'email' => 'hello@site.com',
            'domain' => 'site.com',
        ]);

        RetentionRule::create([
            'email_account_id' => $account->id,
            'name' => 'Rule 1',
            'filter_type' => 'domain',
            'filter_value' => 'site.com',
            'retention_days' => 10,
            'action' => 'archive',
        ]);

        $this->assertEquals($user->id, $account->user->id);
        $this->assertCount(1, $user->emailAccounts);
        $this->assertCount(1, $account->senders);
        $this->assertCount(0, $account->messagesMeta);
        $this->assertCount(1, $account->retentionRules);
    }
}
