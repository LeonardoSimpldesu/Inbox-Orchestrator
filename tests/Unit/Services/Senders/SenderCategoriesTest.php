<?php

namespace Tests\Unit\Services\Senders;

use App\Models\EmailAccount;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use App\Services\Senders\SenderCategories;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SenderCategoriesTest extends TestCase
{
    private EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = EmailAccount::factory()->create();
    }

    private function sender(string $email, ?string $name = null, ?EmailAccount $account = null): EmailSender
    {
        return EmailSender::create(['email_account_id' => ($account ?? $this->account)->id, 'email' => $email, 'name' => $name, 'domain' => str($email)->after('@'), 'total_messages_received' => 3]);
    }

    private function categoryRule(EmailSender $sender, string $label): RetentionRule
    {
        return RetentionRule::factory()->withConditions([['sender', $sender->email]])->create(['email_account_id' => $sender->email_account_id, 'kind' => 'sender_category', 'name' => 'x', 'retention_days' => 0, 'action' => 'add_label', 'target_label' => $label, 'is_active' => true]);
    }

    private function ruleFor(string $email): Builder
    {
        return RetentionRule::whereHas('conditions', fn (Builder $query) => $query->where('type', 'sender')->where('value', $email));
    }

    public function test_reads_categories_in_one_query(): void
    {
        $banco = $this->sender('a@banco.com');
        $none = $this->sender('b@x.com');
        $other = $this->sender('a@banco.com', account: EmailAccount::factory()->create());
        $this->categoryRule($banco, 'Label_1');
        $this->categoryRule($other, 'Label_9');
        RetentionRule::factory()->withConditions([['sender', 'b@x.com']])->create(['email_account_id' => $this->account->id, 'action' => 'add_label', 'target_label' => 'Label_5']);

        DB::enableQueryLog();
        $categories = (new SenderCategories)->forSenders(collect([$banco, $none, $other]));
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([$banco->id => 'Label_1', $none->id => null, $other->id => 'Label_9'], $categories);
        $this->assertSame([], (new SenderCategories)->forSenders(collect()));
    }

    public function test_assign_creates_updates_and_deletes_rules(): void
    {
        $this->freezeTime();
        $new = $this->sender('new@banco.com', 'Banco Novo');
        $moved = $this->sender('moved@banco.com');
        $this->categoryRule($moved, 'Label_old');

        $changes = (new SenderCategories)->assign($this->account, collect([$new, $moved]), 'Label_1', 'Banco');

        $this->assertSame([$new->id => ['old' => null, 'new' => 'Label_1'], $moved->id => ['old' => 'Label_old', 'new' => 'Label_1']], $changes);
        $rule = $this->ruleFor('new@banco.com')->sole();
        $this->assertSame(['Categoria Banco: Banco Novo', 'sender_category', 'add_label', 'Label_1', 0, true], [$rule->name, $rule->kind, $rule->action, $rule->target_label, $rule->retention_days, $rule->is_active]);
        $this->assertSame([['type' => 'sender', 'value' => 'new@banco.com']], $rule->conditions->map->only(['type', 'value'])->all());
        $this->assertSame(now()->timestamp, $rule->simulated_at->timestamp);
        $this->assertSame('Categoria Banco: moved@banco.com', $this->ruleFor('moved@banco.com')->sole()->name);
        $this->assertSame(2, RetentionRule::count());

        $removed = (new SenderCategories)->assign($this->account, collect([$moved]), null, null);

        $this->assertSame([$moved->id => ['old' => 'Label_1', 'new' => null]], $removed);
        $this->assertFalse($this->ruleFor('moved@banco.com')->exists());
    }

    public function test_assign_skips_unchanged(): void
    {
        $sender = $this->sender('a@banco.com');
        $rule = $this->categoryRule($sender, 'Label_1');

        $this->assertSame([], (new SenderCategories)->assign($this->account, collect([$sender]), 'Label_1', 'Banco'));
        $this->assertSame([], (new SenderCategories)->assign($this->account, collect([$this->sender('z@x.com')]), null, null));
        $this->assertSame('x', $rule->fresh()->name);
    }

    public function test_long_names_are_truncated(): void
    {
        $sender = $this->sender('a@b.com', str_repeat('N', 150));

        (new SenderCategories)->assign($this->account, collect([$sender]), 'Label_1', 'Banco');

        $this->assertSame(100, mb_strlen(RetentionRule::sole()->name));
    }

    public function test_assign_uses_constant_queries(): void
    {
        $count = function (int $senders, string $prefix): int {
            $list = collect(range(1, $senders))->map(fn ($i) => $this->sender("{$prefix}{$i}@x.com"));
            $list->take(intdiv($senders, 2))->each(fn ($s) => $this->categoryRule($s, 'Label_old'));

            DB::flushQueryLog();
            DB::enableQueryLog();
            (new SenderCategories)->assign($this->account, $list, 'Label_1', 'Banco');
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(2, 'a'), $count(30, 'b'));
    }
}
