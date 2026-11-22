<?php

namespace Tests\Feature\RetentionRules;

use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use App\Jobs\ExecuteRetentionRuleJob;
use App\Models\EmailAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeEmailProvider;

class RetentionRuleActionsTest extends RetentionRulesTestCase
{
    public function test_simulate_endpoint(): void
    {
        $this->provider->addMessages(FakeEmailProvider::message('m1', 'Loja <promo@loja.com>', CarbonImmutable::now()->subDays(30), ['labelIds' => ['INBOX', 'CATEGORY_PROMOTIONS']]));
        $rule = $this->rule();

        $this->actingAs($this->user)->from('/retention-rules')->post("/retention-rules/{$rule->id}/simulate")
            ->assertRedirect('/retention-rules')
            ->assertSessionHas('simulation', fn (array $s) => $s['rule_id'] === $rule->id && $s['total'] === 1 && $s['capped'] === false && $s['sample'][0]['subject'] === 'Assunto m1');

        $this->assertNotNull($rule->fresh()->simulated_at);

        $this->actingAs($this->user)->withSession(['simulation' => ['rule_id' => $rule->id, 'total' => 1, 'capped' => false, 'sample' => []]])
            ->get('/retention-rules')->assertInertia(fn (Assert $page) => $page->where('simulation.total', 1));
    }

    public function test_simulate_errors(): void
    {
        $rule = $this->rule();

        $this->provider->failNext['listAllMessageIds'] = new ProviderUnavailable('rate_limited');
        $this->actingAs($this->user)->post("/retention-rules/{$rule->id}/simulate")
            ->assertSessionHas('error', 'Não foi possível simular agora. Tente de novo em alguns minutos.');

        $this->provider->failNext['listAllMessageIds'] = new ReauthorizationRequired('invalid_grant');
        $this->actingAs($this->user)->post("/retention-rules/{$rule->id}/simulate")
            ->assertSessionHas('error', 'Reconecte a conta antes de usar as regras.');

        $missing = $this->rule(['action' => 'add_label', 'target_label' => 'Label_404']);
        $this->actingAs($this->user)->post("/retention-rules/{$missing->id}/simulate")
            ->assertSessionHas('error', 'O marcador desta regra não existe mais no Gmail.');

        $this->assertNull($rule->fresh()->simulated_at);
    }

    public function test_needs_reauth_account_refused(): void
    {
        $this->account->update(['reauth_required_at' => now()]);
        $rule = $this->rule();

        $this->actingAs($this->user)->post("/retention-rules/{$rule->id}/simulate")
            ->assertSessionHas('error', 'Reconecte a conta antes de usar as regras.');
        $this->assertSame([], $this->provider->listCalls);
    }

    public function test_run_now_dispatches_active_rules_of_user(): void
    {
        Queue::fake();
        $active = $this->rule(['is_active' => true, 'simulated_at' => now()]);
        $this->rule();
        $this->rule(['is_active' => true], EmailAccount::factory()->create());
        $this->rule(['is_active' => true], EmailAccount::factory()->for($this->user)->needsReauth()->create());

        $this->actingAs($this->user)->from('/retention-rules')->post('/retention-rules/run')
            ->assertSessionHas('success', 'Execução das regras iniciada.');

        Queue::assertPushed(ExecuteRetentionRuleJob::class, 1);
        Queue::assertPushed(ExecuteRetentionRuleJob::class, fn (ExecuteRetentionRuleJob $job) => $job->ruleId === $active->id && $job->accountId === $this->account->id);
    }

    public function test_run_now_without_rules(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->post('/retention-rules/run')->assertSessionHas('error', 'Nenhuma regra ativa para executar.');

        Queue::assertNothingPushed();
    }

    public function test_labels_endpoints(): void
    {
        $this->actingAs($this->user)->getJson("/accounts/{$this->account->id}/labels")
            ->assertOk()
            ->assertExactJson(['labels' => [['id' => 'Label_1', 'name' => 'Banco'], ['id' => 'Label_2', 'name' => 'Notas Fiscais']]]);

        $this->actingAs($this->user)->postJson("/accounts/{$this->account->id}/labels", ['name' => 'Viagens'])
            ->assertCreated()
            ->assertExactJson(['label' => ['id' => 'Label_3', 'name' => 'Viagens']]);

        $this->actingAs($this->user)->postJson("/accounts/{$this->account->id}/labels", ['name' => '/ruim'])->assertUnprocessable();
        $this->actingAs($this->user)->postJson("/accounts/{$this->account->id}/labels", ['name' => ''])->assertUnprocessable();

        $this->provider->failNext['listLabels'] = new ProviderUnavailable('rate_limited');
        $this->actingAs($this->user)->getJson("/accounts/{$this->account->id}/labels")
            ->assertStatus(503)
            ->assertJson(['message' => 'Não foi possível carregar os marcadores do Gmail. Tente de novo.']);

        $this->provider->failNext['createLabel'] = new ProviderUnavailable('http_400');
        $this->actingAs($this->user)->postJson("/accounts/{$this->account->id}/labels", ['name' => 'X'])->assertStatus(503);

        $foreign = EmailAccount::factory()->create();
        $this->actingAs($this->user)->getJson("/accounts/{$foreign->id}/labels")->assertNotFound();
        $this->actingAs($this->user)->postJson("/accounts/{$foreign->id}/labels", ['name' => 'X'])->assertNotFound();
    }

    public function test_throttled(): void
    {
        Queue::fake();

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($this->user)->post('/retention-rules/run');
        }

        $this->actingAs($this->user)->post('/retention-rules/run')->assertStatus(429);
    }
}
