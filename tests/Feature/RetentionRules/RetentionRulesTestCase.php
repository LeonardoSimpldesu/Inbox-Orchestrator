<?php

namespace Tests\Feature\RetentionRules;

use App\Contracts\EmailProviderInterface;
use App\Models\EmailAccount;
use App\Models\RetentionRule;
use App\Models\User;
use Tests\Fakes\FakeEmailProvider;
use Tests\TestCase;

abstract class RetentionRulesTestCase extends TestCase
{
    protected FakeEmailProvider $provider;

    protected User $user;

    protected EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeEmailProvider;
        $this->provider->labels = [['id' => 'Label_1', 'name' => 'Banco'], ['id' => 'Label_2', 'name' => 'Notas Fiscais']];
        $this->app->instance(EmailProviderInterface::class, $this->provider);
        $this->user = User::factory()->create();
        $this->account = EmailAccount::factory()->for($this->user)->create(['account_email' => 'me@gmail.com']);
    }

    /**
     * @param  list<array{0: string, 1: string}>|null  $conditions  [type, value] pairs; defaults to Promoções
     */
    protected function rule(array $attributes = [], ?EmailAccount $account = null, ?array $conditions = null): RetentionRule
    {
        $rule = RetentionRule::create(array_merge([
            'email_account_id' => ($account ?? $this->account)->id,
            'name' => 'Promoções em 15 dias',
            'retention_days' => 15,
            'action' => 'archive',
        ], $attributes));

        $conditions ??= [['category', 'promotions']];
        $rule->replaceConditions(array_map(fn (array $c) => ['type' => $c[0], 'value' => $c[1]], $conditions));

        return $rule;
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'email_account_id' => $this->account->id,
            'name' => 'Promoções em 15 dias',
            'conditions' => [['type' => 'category', 'value' => 'promotions']],
            'retention_days' => 15,
            'action' => 'archive',
            'target_label' => null,
            'archive_after_label' => false,
        ], $overrides);
    }
}
