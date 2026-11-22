<?php

namespace Tests\Feature\Console;

use App\Models\EmailAccount;
use App\Models\EmailMessageMeta;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurgeDisconnectedAccountsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00'));
    }

    private function withData(EmailAccount $account): EmailAccount
    {
        $sender = EmailSender::create(['email_account_id' => $account->id, 'email' => "s{$account->id}@x.com", 'domain' => 'x.com']);
        EmailMessageMeta::create([
            'email_account_id' => $account->id, 'provider_message_id' => "m{$account->id}", 'provider_thread_id' => 't',
            'email_sender_id' => $sender->id, 'sender_email' => $sender->email, 'sender_domain' => 'x.com', 'labels' => ['INBOX'], 'received_at' => now(),
        ]);
        RetentionRule::create(['email_account_id' => $account->id, 'name' => 'R', 'filter_type' => 'sender', 'filter_value' => $sender->email, 'retention_days' => 0, 'action' => 'archive']);

        return $account;
    }

    public function test_purges_accounts_disconnected_for_more_than_90_days_with_their_data(): void
    {
        $old = $this->withData(EmailAccount::factory()->create(['deleted_at' => now()->subDays(91)]));
        $recent = $this->withData(EmailAccount::factory()->create(['deleted_at' => now()->subDays(89)]));
        $active = $this->withData(EmailAccount::factory()->create());

        $this->artisan('inbox:purge-disconnected-accounts')
            ->expectsOutputToContain('1 conta(s) desconectada(s) apagada(s).')
            ->assertExitCode(0);

        $this->assertNull(EmailAccount::withTrashed()->find($old->id));
        $this->assertSame(0, EmailSender::where('email_account_id', $old->id)->count());
        $this->assertSame(0, EmailMessageMeta::where('email_account_id', $old->id)->count());
        $this->assertSame(0, RetentionRule::where('email_account_id', $old->id)->count());
        $this->assertTrue(EmailAccount::withTrashed()->find($recent->id)->trashed());
        $this->assertSame(1, EmailSender::where('email_account_id', $recent->id)->count());
        $this->assertModelExists($active);
    }

    public function test_retention_period_is_configurable(): void
    {
        config(['app.disconnected_account_retention_days' => 30]);
        $account = EmailAccount::factory()->create(['deleted_at' => now()->subDays(31)]);

        $this->artisan('inbox:purge-disconnected-accounts');

        $this->assertNull(EmailAccount::withTrashed()->find($account->id));
    }

    public function test_purge_uses_constant_queries(): void
    {
        $count = function (int $accounts): int {
            EmailAccount::factory()->count($accounts)->create(['deleted_at' => now()->subDays(100)]);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->artisan('inbox:purge-disconnected-accounts');
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(1), $count(20));
    }

    public function test_scheduled_hourly(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'inbox:purge-disconnected-accounts'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }
}
