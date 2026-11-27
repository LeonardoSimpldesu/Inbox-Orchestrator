<?php

namespace Tests\Unit\Services\Retention;

use App\Contracts\EmailProviderInterface;
use App\Models\EmailAccount;
use App\Models\RetentionRule;
use App\Services\Providers\MessageMetadata;
use App\Services\Sync\MessageStore;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeEmailProvider;
use Tests\TestCase;

abstract class RetentionTestCase extends TestCase
{
    protected FakeEmailProvider $provider;

    protected EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-22 12:00:00'));
        $this->provider = new FakeEmailProvider;
        $this->app->instance(EmailProviderInterface::class, $this->provider);
        $this->account = EmailAccount::factory()->create();
    }

    /**
     * @param  list<array{0: string, 1: string}>|null  $conditions  [type, value] pairs; defaults to the filter columns
     */
    protected function rule(array $attributes = [], ?array $conditions = null): RetentionRule
    {
        $rule = RetentionRule::create(array_merge([
            'email_account_id' => $this->account->id,
            'name' => 'Promoções em 15 dias',
            'filter_type' => 'category',
            'filter_value' => 'promotions',
            'retention_days' => 15,
            'action' => 'archive',
            'is_active' => true,
            'simulated_at' => now(),
        ], $attributes));

        $conditions ??= [[$rule->filter_type, $rule->filter_value]];
        $rule->replaceConditions(array_map(fn (array $c) => ['type' => $c[0], 'value' => $c[1]], $conditions));

        return $rule;
    }

    /**
     * Adds $count promotional inbox messages, one per day going back from $daysAgo.
     *
     * @return list<MessageMetadata>
     */
    protected function promotions(int $count, int $daysAgo = 20, array $labels = ['INBOX', 'CATEGORY_PROMOTIONS'], bool $store = false, string $prefix = 'p'): array
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $messages[] = FakeEmailProvider::message("{$prefix}{$i}", "Loja {$i} <promo{$i}@loja.com>", now()->toImmutable()->subDays($daysAgo + $i), ['labelIds' => $labels, 'subject' => "Oferta {$i}"]);
        }
        $this->provider->addMessages(...$messages);

        if ($store) {
            (new MessageStore)->store($this->account, $messages);
        }

        return $messages;
    }
}
