<?php

namespace Tests\Unit\Models;

use App\Models\EmailAccount;
use App\Models\EmailMessageMeta;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use App\Models\User;
use Illuminate\Support\Facades\Date;
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

    public function test_oauth_tokens_are_never_serialized(): void
    {
        $account = EmailAccount::create([
            'user_id' => User::factory()->create()->id,
            'provider' => 'gmail',
            'account_email' => 'secure@gmail.com',
            'access_token' => 'plain_text_access_token_12345',
            'refresh_token' => 'plain_text_refresh_token_67890',
        ]);

        $this->assertArrayNotHasKey('access_token', $account->toArray());
        $this->assertArrayNotHasKey('refresh_token', $account->toArray());
        $this->assertStringNotContainsString('plain_text_access_token_12345', $account->toJson());
        $this->assertStringNotContainsString('plain_text_refresh_token_67890', $account->toJson());

        // Still readable (decrypted) in server-side code.
        $this->assertSame('plain_text_access_token_12345', $account->access_token);
        $this->assertSame('plain_text_refresh_token_67890', $account->refresh_token);
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

        RetentionRule::factory()->withConditions([['domain', 'promo.com']])->create([
            'email_account_id' => $account->id,
            'name' => 'Clean spam',
            'retention_days' => 7,
            'action' => 'trash',
        ]);

        $this->assertDatabaseHas('email_senders', ['id' => $sender->id]);
        $this->assertDatabaseHas('email_messages_meta', ['provider_message_id' => 'msg_101']);

        $account->forceDelete();

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

        RetentionRule::factory()->withConditions([['domain', 'site.com']])->create([
            'email_account_id' => $account->id,
            'name' => 'Rule 1',
            'retention_days' => 10,
            'action' => 'archive',
        ]);

        $this->assertEquals($user->id, $account->user->id);
        $this->assertCount(1, $user->emailAccounts);
        $this->assertCount(1, $account->senders);
        $this->assertCount(0, $account->messagesMeta);
        $this->assertCount(1, $account->retentionRules);
    }

    public function test_connected_account_status(): void
    {
        config(['services.google.testing' => true]);
        $this->travelTo(Date::parse('2026-11-18 12:00:00'));
        $account = EmailAccount::factory()->create(['authorized_at' => now()]);

        $this->assertFalse($account->needsReauth());
        $this->assertSame('2026-11-25', $account->reconnectBy()?->toDateString());
        $this->assertFalse($account->expiringSoon());
        $this->assertSame('connected', $account->summary()['status']);
    }

    public function test_reauth_required_at_means_needs_reauth(): void
    {
        config(['services.google.testing' => false]);
        $account = EmailAccount::factory()->needsReauth()->create();

        $this->assertTrue($account->needsReauth());
        $this->assertSame('needs_reauth', $account->summary()['status']);
        $this->assertFalse($account->expiringSoon());
    }

    public function test_testing_mode_window_expired_means_needs_reauth(): void
    {
        config(['services.google.testing' => true]);
        $account = EmailAccount::factory()->create(['authorized_at' => now()->subDays(8)]);

        $this->assertTrue($account->needsReauth());
        $this->assertFalse($account->expiringSoon());
    }

    public function test_no_deadline_when_testing_mode_is_off(): void
    {
        config(['services.google.testing' => false]);
        $account = EmailAccount::factory()->create(['authorized_at' => now()->subDays(30)]);

        $this->assertNull($account->reconnectBy());
        $this->assertFalse($account->needsReauth());
        $this->assertFalse($account->expiringSoon());
        $this->assertNull($account->summary()['reconnect_by']);
    }

    public function test_expiring_soon_within_two_days(): void
    {
        config(['services.google.testing' => true]);

        $soon = EmailAccount::factory()->create(['authorized_at' => now()->subDays(5)->subHour()]);
        $later = EmailAccount::factory()->create(['authorized_at' => now()->subDays(4)]);

        $this->assertTrue($soon->expiringSoon());
        $this->assertFalse($later->expiringSoon());
    }

    public function test_account_without_authorized_at_has_no_deadline(): void
    {
        config(['services.google.testing' => true]);
        $account = EmailAccount::factory()->create(['authorized_at' => null]);

        $this->assertNull($account->reconnectBy());
        $this->assertFalse($account->needsReauth());
    }

    public function test_summary_has_only_public_fields(): void
    {
        config(['services.google.testing' => true]);
        $account = EmailAccount::factory()->create(['account_email' => 'me@gmail.com', 'history_id' => 'h1']);

        $summary = $account->summary();

        $this->assertSame(
            ['id', 'provider', 'account_email', 'last_synced_at', 'status', 'reconnect_by', 'expiring_soon', 'sync'],
            array_keys($summary),
        );
        $this->assertSame('me@gmail.com', $summary['account_email']);
        $this->assertStringNotContainsString($account->access_token, json_encode($summary));
        $this->assertStringNotContainsString($account->refresh_token, json_encode($summary));
    }

    public function test_sync_state_transitions(): void
    {
        $account = EmailAccount::factory()->create(['history_id' => null]);
        $this->assertSame('importing_recent', $account->syncState());

        $account->history_id = '123';
        $this->assertSame('importing_history', $account->syncState());

        $account->history_completed_at = now();
        $this->assertSame('complete', $account->syncState());
    }

    public function test_summary_includes_sync_progress_without_queries(): void
    {
        $account = EmailAccount::factory()->create([
            'history_id' => '9',
            'oldest_synced_at' => '2024-03-12 10:00:00',
            'last_synced_at' => '2026-11-18 12:00:00',
        ])->fresh();

        DB::enableQueryLog();
        $summary = $account->summary();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries);
        $this->assertSame(['state', 'oldest_synced_at', 'last_synced_at', 'manual_import'], array_keys($summary['sync']));
        $this->assertSame('importing_history', $summary['sync']['state']);
        $this->assertSame('2024-03-12', $summary['sync']['oldest_synced_at']);
        $this->assertSame($summary['last_synced_at'], $summary['sync']['last_synced_at']);
    }

    public function test_summary_reports_a_recent_manual_history_import(): void
    {
        $account = EmailAccount::factory()->create(['history_id' => '1', 'history_import_requested_at' => now()->subMinutes(5)]);
        $this->assertTrue($account->summary()['sync']['manual_import']);

        $account->history_import_requested_at = now()->subMinutes(31);
        $this->assertFalse($account->summary()['sync']['manual_import']);

        $account->history_import_requested_at = null;
        $this->assertFalse($account->summary()['sync']['manual_import']);
    }
}
