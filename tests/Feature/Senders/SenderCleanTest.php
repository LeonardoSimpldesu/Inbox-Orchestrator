<?php

namespace Tests\Feature\Senders;

use App\Jobs\CleanSenderMailJob;
use App\Models\EmailAccount;
use App\Models\RetentionRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class SenderCleanTest extends SendersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->freezeTime();
    }

    private function clean(array $data)
    {
        return $this->actingAs($this->user)->from('/senders')->post('/senders/clean', $data);
    }

    public function test_clean_queues_jobs_across_accounts(): void
    {
        $a = $this->sender();
        $b = $this->sender([], EmailAccount::factory()->for($this->user)->create());

        $this->clean(['ids' => [$a->id, $b->id], 'cleanup' => 'trash', 'future' => false])
            ->assertRedirect('/senders')
            ->assertSessionHas('success', 'Limpeza iniciada para 2 remetente(s).');

        Queue::assertPushed(CleanSenderMailJob::class, 2);
        Queue::assertPushed(CleanSenderMailJob::class, fn (CleanSenderMailJob $job) => $job->senderId === $b->id && $job->accountId === $b->email_account_id && $job->cleanup === 'trash');
        $this->assertSame(0, RetentionRule::count());
    }

    public function test_future_creates_rule(): void
    {
        $sender = $this->sender(['name' => 'Banco Inter', 'email' => 'inter@banco.com']);

        $this->clean(['ids' => [$sender->id], 'cleanup' => 'archive', 'future' => true])
            ->assertSessionHas('success', 'Limpeza iniciada para 1 remetente(s). Regra criada para os próximos e-mails.');

        $rule = RetentionRule::sole();
        $this->assertSame(
            [$this->account->id, 'Arquivar e-mails de Banco Inter', 'sender_cleanup', 0, 'archive', true, now()->timestamp],
            [$rule->email_account_id, $rule->name, $rule->kind, $rule->retention_days, $rule->action, $rule->is_active, $rule->simulated_at->timestamp],
        );
        $this->assertSame([['type' => 'sender', 'value' => 'inter@banco.com']], $rule->conditions->map->only(['type', 'value'])->all());

        $trash = $this->sender(['name' => null, 'email' => 'spam@x.com']);
        $this->clean(['ids' => [$trash->id], 'cleanup' => 'trash', 'future' => true]);
        $this->assertSame('Mandar para a lixeira e-mails de spam@x.com', RetentionRule::latest('id')->first()->name);
    }

    public function test_future_rule_is_not_duplicated(): void
    {
        $sender = $this->sender();

        $this->clean(['ids' => [$sender->id], 'cleanup' => 'archive', 'future' => true]);
        $this->clean(['ids' => [$sender->id], 'cleanup' => 'archive', 'future' => true]);
        $this->clean(['ids' => [$sender->id], 'cleanup' => 'trash', 'future' => true]);

        $this->assertSame(['archive', 'trash'], RetentionRule::orderBy('id')->pluck('action')->all());
    }

    public function test_a_manual_rule_with_more_conditions_does_not_count_as_the_cleanup_rule(): void
    {
        $sender = $this->sender(['email' => 'news@loja.com']);
        RetentionRule::factory()->withConditions([['sender', 'news@loja.com'], ['subject', 'Oferta']])
            ->create(['email_account_id' => $this->account->id, 'filter_type' => 'sender', 'filter_value' => 'news@loja.com', 'action' => 'trash', 'retention_days' => 0]);

        $this->clean(['ids' => [$sender->id], 'cleanup' => 'trash', 'future' => true]);

        $this->assertSame(1, RetentionRule::where('kind', 'sender_cleanup')->count());
    }

    public function test_needs_reauth_accounts_skipped(): void
    {
        $ok = $this->sender();
        $blocked = $this->sender([], EmailAccount::factory()->for($this->user)->needsReauth()->create());

        $this->clean(['ids' => [$ok->id, $blocked->id], 'cleanup' => 'archive', 'future' => true])
            ->assertSessionHas('success', 'Limpeza iniciada para 1 remetente(s). Regra criada para os próximos e-mails.');

        Queue::assertPushed(CleanSenderMailJob::class, 1);
        $this->assertSame([$ok->email_account_id], RetentionRule::pluck('email_account_id')->all());
    }

    public function test_nothing_cleanable(): void
    {
        $this->account->update(['reauth_required_at' => now()]);

        $this->clean(['ids' => [$this->sender()->id], 'cleanup' => 'archive'])
            ->assertSessionHas('error', 'Nenhum remetente selecionado pode ser limpo agora.');

        Queue::assertNothingPushed();
    }

    public function test_validation_and_isolation(): void
    {
        $this->clean(['ids' => [], 'cleanup' => 'archive'])->assertSessionHasErrors('ids');
        $this->clean(['ids' => [1], 'cleanup' => 'burn'])->assertSessionHasErrors('cleanup');
        $this->clean(['ids' => ['x'], 'cleanup' => 'archive'])->assertSessionHasErrors('ids.0');

        $foreign = $this->sender([], EmailAccount::factory()->create());
        $this->clean(['ids' => [$foreign->id], 'cleanup' => 'archive', 'future' => true])
            ->assertSessionHas('error', 'Nenhum remetente selecionado pode ser limpo agora.');
        $this->assertSame(0, RetentionRule::count());
        Queue::assertNothingPushed();
    }

    public function test_guests_are_redirected(): void
    {
        $this->post('/senders/clean')->assertRedirect('/login');
        $this->post('/senders/categorize')->assertRedirect('/login');
    }

    public function test_clean_uses_constant_queries(): void
    {
        $count = function (int $senders): int {
            $ids = collect(range(1, $senders))->map(fn () => $this->sender()->id)->all();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->clean(['ids' => $ids, 'cleanup' => 'archive', 'future' => true]);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(1), $count(40));
    }
}
