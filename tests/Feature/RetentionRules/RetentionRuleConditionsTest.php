<?php

namespace Tests\Feature\RetentionRules;

use App\Models\EmailAccount;
use App\Models\RetentionRule;
use Illuminate\Support\Facades\DB;

class RetentionRuleConditionsTest extends RetentionRulesTestCase
{
    private function conditions(array $pairs): array
    {
        return array_map(fn (array $c) => ['type' => $c[0], 'value' => $c[1]], $pairs);
    }

    private function storedConditions(RetentionRule $rule): array
    {
        return $rule->conditions()->get()->map->only(['type', 'value'])->all();
    }

    public function test_creates_a_rule_with_sender_and_subject(): void
    {
        $this->actingAs($this->user)->from('/retention-rules')->post('/retention-rules', $this->payload([
            'conditions' => $this->conditions([['sender', ' No-Reply@Accounts.Google.com '], ['subject', '  Alerta de segurança ']]),
        ]))->assertSessionHasNoErrors()->assertSessionHas('success', 'Regra criada.');

        $rule = RetentionRule::sole();
        $this->assertSame('manual', $rule->kind);
        $this->assertSame([['type' => 'sender', 'value' => 'no-reply@accounts.google.com'], ['type' => 'subject', 'value' => 'Alerta de segurança']], $this->storedConditions($rule));
    }

    public function test_kind_never_comes_from_the_request(): void
    {
        $this->actingAs($this->user)->post('/retention-rules', $this->payload(['kind' => 'hub_block']))->assertSessionHasNoErrors();

        $this->assertSame('manual', RetentionRule::sole()->kind);
    }

    public function test_condition_validation(): void
    {
        $cases = [
            [[], 'conditions'],
            [array_map(fn (int $i) => ['domain', "d{$i}.com"], range(1, 21)), 'conditions'],
            [[['domain', 'x.com'], ['domain', 'x.com']], 'conditions'],
            [[['label', 'Label_1'], ['label', 'Label_2']], 'conditions'],
            [[['subject', '   ']], 'conditions.0.value'],
            [[['subject', str_repeat('a', 101)]], 'conditions.0.value'],
            [[['domain', 'x.com'], ['subject', 'Oi") OR (in:anywhere']], 'conditions.1.value'],
            [[['subject', 'a"b']], 'conditions.0.value'],
            [[['subject', 'a(b']], 'conditions.0.value'],
            [[['subject', 'a)b']], 'conditions.0.value'],
            [[['subject', 'a{b']], 'conditions.0.value'],
            [[['subject', 'a}b']], 'conditions.0.value'],
            [[['domain', 'x.com'], ['label', 'Label_99']], 'conditions.1.value'],
        ];

        foreach ($cases as [$pairs, $field]) {
            $this->actingAs($this->user)->post('/retention-rules', $this->payload(['conditions' => $this->conditions($pairs)]))
                ->assertSessionHasErrors($field);
        }

        foreach (['x', [['type' => 'domain']], ['not-an-array']] as $malformed) {
            $this->actingAs($this->user)->post('/retention-rules', $this->payload(['conditions' => $malformed]))->assertSessionHasErrors();
        }

        $this->assertSame(0, RetentionRule::count());
    }

    public function test_subject_error_explains_the_forbidden_characters(): void
    {
        $this->actingAs($this->user)->post('/retention-rules', $this->payload(['conditions' => $this->conditions([['subject', 'Oi "você"']])]))
            ->assertSessionHasErrors(['conditions.0.value' => 'O assunto não pode ter aspas, parênteses ou chaves.']);
    }

    public function test_all_accounts_copies_every_condition_in_constant_queries(): void
    {
        $count = function (int $accounts): int {
            RetentionRule::query()->delete();
            EmailAccount::where('user_id', $this->user->id)->whereKeyNot($this->account->id)->forceDelete();
            EmailAccount::factory()->for($this->user)->count($accounts - 1)->create();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->user)->post('/retention-rules', $this->payload([
                'email_account_id' => 'all',
                'conditions' => $this->conditions([['subject', 'Bem-vindo'], ['subject', 'Welcome']]),
                'action' => 'trash',
            ]))->assertSessionHasNoErrors();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(1), $count(3));

        $rules = RetentionRule::with('conditions')->get();
        $this->assertCount(3, $rules);
        foreach ($rules as $rule) {
            $this->assertSame([['type' => 'subject', 'value' => 'Bem-vindo'], ['type' => 'subject', 'value' => 'Welcome']], $rule->conditions->map->only(['type', 'value'])->all());
            $this->assertSame('manual', $rule->kind);
        }
    }

    public function test_all_accounts_refuses_a_label_condition_anywhere(): void
    {
        $this->actingAs($this->user)->post('/retention-rules', $this->payload([
            'email_account_id' => 'all',
            'conditions' => $this->conditions([['domain', 'x.com'], ['label', 'Label_1']]),
        ]))->assertSessionHasErrors(['email_account_id' => 'Marcadores são de cada conta: escolha uma conta para usar marcadores.']);
    }

    public function test_editing_replaces_conditions_and_requires_a_new_simulation(): void
    {
        $rule = $this->rule(['simulated_at' => now(), 'is_active' => true]);

        $this->actingAs($this->user)->put("/retention-rules/{$rule->id}", $this->payload([
            'conditions' => $this->conditions([['domain', 'loja.com'], ['subject', 'Oferta']]),
        ]))->assertSessionHasNoErrors();

        $rule->refresh();
        $this->assertSame([['type' => 'domain', 'value' => 'loja.com'], ['type' => 'subject', 'value' => 'Oferta']], $this->storedConditions($rule));
        $this->assertNull($rule->simulated_at);
        $this->assertFalse($rule->is_active);
    }

    public function test_managed_rules_keep_their_conditions(): void
    {
        $category = $this->rule(['kind' => 'sender_category', 'action' => 'add_label', 'target_label' => 'Label_1'], conditions: [['sender', 'a@x.com']]);
        $block = $this->rule(['kind' => 'hub_block', 'action' => 'trash', 'retention_days' => 0], conditions: [['sender', 'b@x.com']]);

        $this->actingAs($this->user)->put("/retention-rules/{$category->id}", $this->payload([
            'conditions' => $this->conditions([['sender', 'a@x.com'], ['subject', 'Oi']]), 'action' => 'add_label', 'target_label' => 'Label_1',
        ]))->assertSessionHasErrors(['conditions' => 'As condições desta regra são gerenciadas pela página Remetentes.']);

        $this->actingAs($this->user)->put("/retention-rules/{$block->id}", $this->payload([
            'conditions' => $this->conditions([['sender', 'c@x.com']]), 'action' => 'trash',
        ]))->assertSessionHasErrors(['conditions' => 'As condições desta regra são gerenciadas pelo Hub.']);

        $this->actingAs($this->user)->put("/retention-rules/{$block->id}", $this->payload([
            'conditions' => $this->conditions([['sender', 'b@x.com']]), 'action' => 'trash', 'email_account_id' => EmailAccount::factory()->for($this->user)->create()->id,
        ]))->assertSessionHasErrors(['email_account_id' => 'A conta desta regra não pode mudar.']);

        $this->assertSame([['type' => 'sender', 'value' => 'a@x.com']], $this->storedConditions($category));
        $this->assertSame([['type' => 'sender', 'value' => 'b@x.com']], $this->storedConditions($block));
    }

    public function test_managed_rules_keep_their_action_family(): void
    {
        $category = $this->rule(['kind' => 'sender_category', 'action' => 'add_label', 'target_label' => 'Label_1'], conditions: [['sender', 'a@x.com']]);
        $cleanup = $this->rule(['kind' => 'sender_cleanup', 'action' => 'archive', 'retention_days' => 0], conditions: [['sender', 'b@x.com']]);

        $this->actingAs($this->user)->put("/retention-rules/{$category->id}", $this->payload([
            'conditions' => $this->conditions([['sender', 'a@x.com']]), 'action' => 'trash',
        ]))->assertSessionHasErrors(['action' => 'Uma regra de categoria só pode aplicar marcador.']);

        $this->actingAs($this->user)->put("/retention-rules/{$cleanup->id}", $this->payload([
            'conditions' => $this->conditions([['sender', 'b@x.com']]), 'action' => 'add_label', 'target_label' => 'Label_1',
        ]))->assertSessionHasErrors(['action' => 'Esta regra só pode arquivar ou mandar para a lixeira.']);

        $this->actingAs($this->user)->put("/retention-rules/{$cleanup->id}", $this->payload([
            'name' => 'Lixo da loja', 'conditions' => $this->conditions([['sender', 'B@x.com ']]), 'action' => 'trash', 'retention_days' => 3,
        ]))->assertSessionHasNoErrors();

        $cleanup->refresh();
        $this->assertSame(['Lixo da loja', 'trash', 3, 'sender_cleanup'], [$cleanup->name, $cleanup->action, $cleanup->retention_days, $cleanup->kind]);
    }
}
