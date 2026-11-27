<?php

namespace Tests\Unit\Models;

use App\Models\EmailAccount;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use App\Models\User;
use Tests\TestCase;

class UserTest extends TestCase
{
    public function test_user_relations_scope_through_accounts(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();

        foreach ([$alice, $bob] as $user) {
            $account = EmailAccount::create([
                'user_id' => $user->id,
                'provider' => 'gmail',
                'account_email' => "inbox{$user->id}@gmail.com",
                'access_token' => 'a',
                'refresh_token' => 'r',
            ]);
            EmailSender::create(['email_account_id' => $account->id, 'email' => "s{$user->id}@x.com", 'domain' => 'x.com']);
            RetentionRule::factory()->withConditions([['domain', 'x.com']])->create([
                'email_account_id' => $account->id,
                'name' => "Regra {$user->id}",
                'retention_days' => 7,
                'action' => 'trash',
            ]);
        }

        $this->assertSame(["s{$alice->id}@x.com"], $alice->senders()->pluck('email_senders.email')->all());
        $this->assertSame(["Regra {$alice->id}"], $alice->retentionRules()->pluck('retention_rules.name')->all());
        $this->assertSame(["inbox{$alice->id}@gmail.com"], $alice->emailAccounts()->pluck('account_email')->all());
    }
}
