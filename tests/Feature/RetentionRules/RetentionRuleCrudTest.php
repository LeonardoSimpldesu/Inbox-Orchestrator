<?php

namespace Tests\Feature\RetentionRules;

use App\Exceptions\ProviderUnavailable;
use App\Models\EmailAccount;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use App\Models\RuleExecutionLog;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

class RetentionRuleCrudTest extends RetentionRulesTestCase
{
    public function test_guests_are_redirected(): void
    {
        $this->get('/retention-rules')->assertRedirect('/login');
        $this->post('/retention-rules')->assertRedirect('/login');
    }

    public function test_lists_only_own_rules_with_history_without_n_plus_one(): void
    {
        $rule = $this->rule(['filter_type' => 'label', 'filter_value' => 'Label_2', 'action' => 'add_label', 'target_label' => 'Label_1', 'last_run_at' => now()]);
        for ($i = 0; $i < 12; $i++) {
            $rule->logExecution('success', $i);
        }
        $this->rule([], EmailAccount::factory()->create());

        $this->actingAs($this->user)->get('/retention-rules')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('RetentionRules/Index')
            ->has('rules', 1)
            ->where('rules.0.id', $rule->id)
            ->where('rules.0.account_email', 'me@gmail.com')
            ->where('rules.0.filter_label', 'Notas Fiscais')
            ->where('rules.0.target_label_name', 'Banco')
            ->where('rules.0.can_activate', false)
            ->has('rules.0.logs', 10)
            ->where('rules.0.logs.0.affected', 11)
            ->has('accounts', 1)
            ->where('accounts.0.needs_reauth', false)
        );

        $count = function (int $rules): int {
            RetentionRule::query()->delete();
            for ($i = 0; $i < $rules; $i++) {
                $this->rule()->logExecution('success', 1);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->user)->get('/retention-rules')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $this->assertSame($count(1), $count(20));
    }

    public function test_list_falls_back_to_label_ids_when_gmail_is_unavailable(): void
    {
        $this->rule(['filter_type' => 'label', 'filter_value' => 'Label_2']);
        $this->provider->failNext['listLabels'] = new ProviderUnavailable('rate_limited');

        $this->actingAs($this->user)->get('/retention-rules')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rules.0.filter_label', 'Label_2')
            ->where('rules.0.target_label_name', null)
        );
    }

    public function test_create_rule_starts_paused(): void
    {
        $this->actingAs($this->user)->from('/retention-rules')->post('/retention-rules', $this->payload(['filter_type' => 'domain', 'filter_value' => ' Amazon.COM.br ']))
            ->assertRedirect('/retention-rules')
            ->assertSessionHas('success', 'Regra criada.');

        $rule = RetentionRule::sole();
        $this->assertFalse($rule->is_active);
        $this->assertNull($rule->simulated_at);
        $this->assertSame('amazon.com.br', $rule->filter_value);
    }

    public function test_create_and_simulate(): void
    {
        $this->actingAs($this->user)->from('/retention-rules')->post('/retention-rules', $this->payload(['simulate' => true]))
            ->assertSessionHas('simulation', fn (array $simulation) => $simulation['total'] === 0 && $simulation['rule_id'] === RetentionRule::sole()->id);

        $this->assertNotNull(RetentionRule::sole()->simulated_at);
    }

    public function test_validation_rules(): void
    {
        $cases = [
            [['retention_days' => -1], 'retention_days'],
            [['retention_days' => 3651], 'retention_days'],
            [['name' => ''], 'name'],
            [['name' => str_repeat('a', 101)], 'name'],
            [['filter_type' => 'query'], 'filter_type'],
            [['filter_value' => 'primary'], 'filter_value'],
            [['filter_type' => 'domain', 'filter_value' => 'not a domain'], 'filter_value'],
            [['filter_type' => 'sender', 'filter_value' => 'nobody'], 'filter_value'],
            [['action' => 'delete'], 'action'],
            [['action' => 'add_label', 'target_label' => null], 'target_label'],
        ];

        foreach ($cases as [$override, $field]) {
            $this->actingAs($this->user)->post('/retention-rules', $this->payload($override))->assertSessionHasErrors($field);
        }

        $this->assertSame(0, RetentionRule::count());
    }

    public function test_label_must_belong_to_account(): void
    {
        $this->actingAs($this->user)->post('/retention-rules', $this->payload(['filter_type' => 'label', 'filter_value' => 'Label_99']))
            ->assertSessionHasErrors('filter_value');
        $this->actingAs($this->user)->post('/retention-rules', $this->payload(['action' => 'add_label', 'target_label' => 'Label_99']))
            ->assertSessionHasErrors('target_label');

        $foreign = EmailAccount::factory()->create();
        $this->actingAs($this->user)->post('/retention-rules', $this->payload(['email_account_id' => $foreign->id]))
            ->assertSessionHasErrors('email_account_id');

        $this->actingAs($this->user)->post('/retention-rules', $this->payload(['filter_type' => 'label', 'filter_value' => 'Label_1', 'action' => 'add_label', 'target_label' => 'Label_2']))
            ->assertSessionHasNoErrors();
    }

    public function test_labels_unavailable_is_a_validation_error(): void
    {
        $this->provider->failNext['listLabels'] = new ProviderUnavailable('rate_limited');

        $this->actingAs($this->user)->post('/retention-rules', $this->payload(['filter_type' => 'label', 'filter_value' => 'Label_1']))
            ->assertSessionHasErrors(['filter_value' => 'Não foi possível carregar os marcadores do Gmail. Tente de novo.']);
    }

    public function test_editing_pauses_and_requires_new_simulation(): void
    {
        $rule = $this->rule(['is_active' => true, 'simulated_at' => now()]);

        $this->actingAs($this->user)->from('/retention-rules')->put("/retention-rules/{$rule->id}", $this->payload(['retention_days' => 30]))
            ->assertSessionHas('success', 'Regra atualizada. Simule de novo antes de ativar.');

        $rule->refresh();
        $this->assertSame(30, $rule->retention_days);
        $this->assertFalse($rule->is_active);
        $this->assertNull($rule->simulated_at);

        $this->actingAs($this->user)->post("/retention-rules/{$rule->id}/activate")->assertSessionHas('error', 'Simule a regra antes de ativá-la.');
        $this->assertFalse($rule->fresh()->is_active);
    }

    public function test_activate_and_pause(): void
    {
        $rule = $this->rule(['simulated_at' => now()]);

        $this->actingAs($this->user)->post("/retention-rules/{$rule->id}/activate")->assertSessionHas('success', 'Regra ativada.');
        $this->assertTrue($rule->fresh()->is_active);

        $this->actingAs($this->user)->post("/retention-rules/{$rule->id}/pause")->assertSessionHas('success', 'Regra pausada.');
        $this->assertFalse($rule->fresh()->is_active);
    }

    public function test_delete_frees_hub_sender(): void
    {
        $sender = EmailSender::create(['email_account_id' => $this->account->id, 'email' => 'promo@loja.com', 'domain' => 'loja.com', 'unsubscribe_status' => 'blocked', 'unsubscribe_method' => 'rule']);
        $other = EmailSender::create(['email_account_id' => $this->account->id, 'email' => 'news@x.com', 'domain' => 'x.com', 'unsubscribe_status' => 'requested', 'unsubscribe_method' => 'one_click']);
        $rule = $this->rule(['filter_type' => 'sender', 'filter_value' => 'promo@loja.com', 'retention_days' => 0]);
        $rule->logExecution('success', 3);

        $this->actingAs($this->user)->delete("/retention-rules/{$rule->id}")->assertSessionHas('success', 'Regra apagada.');

        $this->assertModelMissing($rule);
        $this->assertSame(0, RuleExecutionLog::count());
        $this->assertSame(['none', null], [$sender->fresh()->unsubscribe_status, $sender->fresh()->unsubscribe_method]);
        $this->assertSame('requested', $other->fresh()->unsubscribe_status);
    }

    public function test_delete_keeps_sender_blocked_while_another_rule_targets_it(): void
    {
        $sender = EmailSender::create(['email_account_id' => $this->account->id, 'email' => 'promo@loja.com', 'domain' => 'loja.com', 'unsubscribe_status' => 'blocked', 'unsubscribe_method' => 'rule']);
        $hubRule = $this->rule(['filter_type' => 'sender', 'filter_value' => 'promo@loja.com', 'retention_days' => 0, 'is_active' => true]);
        $duplicate = $this->rule(['filter_type' => 'sender', 'filter_value' => 'promo@loja.com', 'retention_days' => 30]);

        $this->actingAs($this->user)->delete("/retention-rules/{$duplicate->id}");
        $this->assertSame('blocked', $sender->fresh()->unsubscribe_status);

        $this->actingAs($this->user)->delete("/retention-rules/{$hubRule->id}");
        $this->assertSame('none', $sender->fresh()->unsubscribe_status);
    }

    public function test_editing_a_sender_rule_away_from_the_sender_releases_it(): void
    {
        $sender = EmailSender::create(['email_account_id' => $this->account->id, 'email' => 'news@x.com', 'domain' => 'x.com', 'unsubscribe_status' => 'blocked', 'unsubscribe_method' => 'rule']);
        $rule = $this->rule(['filter_type' => 'sender', 'filter_value' => 'news@x.com', 'retention_days' => 0, 'is_active' => true]);

        $this->actingAs($this->user)->put("/retention-rules/{$rule->id}", $this->payload(['filter_type' => 'sender', 'filter_value' => 'news@x.com', 'retention_days' => 7]));
        $this->assertSame('blocked', $sender->fresh()->unsubscribe_status);

        $this->actingAs($this->user)->put("/retention-rules/{$rule->id}", $this->payload(['filter_type' => 'domain', 'filter_value' => 'x.com']));
        $this->assertSame('none', $sender->fresh()->unsubscribe_status);
    }

    public function test_foreign_rules_are_404(): void
    {
        $foreign = $this->rule([], EmailAccount::factory()->create());
        $gone = $this->rule([], EmailAccount::factory()->for($this->user)->disconnected()->create());

        foreach ([$foreign, $gone] as $rule) {
            $this->actingAs($this->user)->put("/retention-rules/{$rule->id}", $this->payload())->assertNotFound();
            $this->actingAs($this->user)->delete("/retention-rules/{$rule->id}")->assertNotFound();
            $this->actingAs($this->user)->post("/retention-rules/{$rule->id}/simulate")->assertNotFound();
            $this->actingAs($this->user)->post("/retention-rules/{$rule->id}/activate")->assertNotFound();
        }

        $this->assertModelExists($foreign);
    }
}
