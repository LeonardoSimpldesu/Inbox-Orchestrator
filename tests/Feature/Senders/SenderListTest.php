<?php

namespace Tests\Feature\Senders;

use App\Exceptions\ProviderUnavailable;
use App\Models\EmailAccount;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

class SenderListTest extends SendersTestCase
{
    private function emails(string $query = ''): array
    {
        return collect($this->actingAs($this->user)->get('/senders'.$query)->viewData('page')['props']['senders']['data'])
            ->pluck('email')->sort()->values()->all();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/senders')->assertRedirect('/login');
    }

    public function test_lists_non_subscriptions_by_default(): void
    {
        $person = $this->sender(['email' => 'maria@gmail.com', 'name' => 'Maria']);
        $this->sender(['email' => 'news@x.com', 'unsubscribe_mailto' => 'mailto:u@x.com']);
        $this->sender(['email' => 'zero@x.com', 'total_messages_received' => 0]);
        $this->categorize($person, 'Label_1');

        $this->actingAs($this->user)->get('/senders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Senders/Index')
            ->has('senders.data', 1)
            ->where('senders.data.0.email', 'maria@gmail.com')
            ->where('senders.data.0.account_email', 'me@gmail.com')
            ->where('senders.data.0.account_id', $this->account->id)
            ->where('senders.data.0.is_subscription', false)
            ->where('senders.data.0.category', ['label_id' => 'Label_1', 'name' => 'Banco'])
            ->where('senders.data.0.applying', false)
            ->where('senders.data.0.gmail_url', 'https://mail.google.com/mail/u/?authuser=me%40gmail.com#search/from:maria%40gmail.com')
            ->where('filters', ['account' => null, 'category' => null, 'q' => '', 'all' => false])
            ->has('accounts', 1)
        );
    }

    public function test_all_includes_subscriptions_with_flag(): void
    {
        $this->sender(['email' => 'maria@gmail.com']);
        $this->sender(['email' => 'news@x.com', 'unsubscribe_mailto' => 'mailto:u@x.com']);

        $this->assertSame(['maria@gmail.com', 'news@x.com'], $this->emails('?all=1'));
        $this->actingAs($this->user)->get('/senders?all=1')->assertInertia(fn (Assert $page) => $page
            ->where('senders.data.1.is_subscription', true)
            ->where('filters.all', true)
        );
    }

    public function test_filters_account_category_and_search(): void
    {
        $second = EmailAccount::factory()->for($this->user)->create();
        $banco = $this->sender(['email' => 'inter@banco.com', 'name' => 'Banco Inter']);
        $this->categorize($banco, 'Label_1');
        $this->sender(['email' => 'maria@gmail.com', 'name' => 'Maria']);
        // A manual rule shaped like a category is not one.
        RetentionRule::factory()->withConditions([['sender', 'maria@gmail.com']])->create(['email_account_id' => $this->account->id, 'action' => 'add_label', 'target_label' => 'Label_1']);
        $this->sender(['email' => 'joao@gmail.com'], $second);
        $foreign = EmailAccount::factory()->create();

        $this->assertSame(['joao@gmail.com'], $this->emails("?account={$second->id}"));
        $this->assertSame(['inter@banco.com', 'joao@gmail.com', 'maria@gmail.com'], $this->emails("?account={$foreign->id}"));
        $this->assertSame(['inter@banco.com'], $this->emails('?category=Label_1'));
        $this->assertSame(['joao@gmail.com', 'maria@gmail.com'], $this->emails('?category=none'));
        $this->assertSame(['inter@banco.com'], $this->emails('?q=INTER'));

        $this->actingAs($this->user)->get("/senders?account={$this->account->id}")->assertInertia(fn (Assert $page) => $page
            ->where('labels', [['id' => 'Label_1', 'name' => 'Banco'], ['id' => 'Label_2', 'name' => 'Família']])
        );
    }

    public function test_category_names_fall_back_to_ids_and_applying_flag(): void
    {
        $sender = $this->sender(['label_applying_at' => now()]);
        $this->categorize($sender, 'Label_1');
        $this->provider->failNext['listLabels'] = new ProviderUnavailable('rate_limited');

        $this->actingAs($this->user)->get('/senders')->assertInertia(fn (Assert $page) => $page
            ->where('senders.data.0.category', ['label_id' => 'Label_1', 'name' => 'Label_1'])
            ->where('senders.data.0.applying', true)
        );
    }

    public function test_page_survives_gmail_outage_when_filtering_by_account(): void
    {
        $this->sender();
        $this->provider->failNext['listLabels'] = new ProviderUnavailable('request_failed');

        $this->actingAs($this->user)->get("/senders?account={$this->account->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('labels', []));
    }

    public function test_isolated_per_user(): void
    {
        $this->sender(['email' => 'other@x.com'], EmailAccount::factory()->create());
        $this->sender(['email' => 'gone@x.com'], EmailAccount::factory()->for($this->user)->disconnected()->create());

        $this->assertSame([], $this->emails());
    }

    public function test_list_uses_constant_queries(): void
    {
        $count = function (int $senders): int {
            EmailSender::query()->delete();
            for ($i = 0; $i < $senders; $i++) {
                $sender = $this->sender();
                if ($i % 2 === 0) {
                    $this->categorize($sender, 'Label_1');
                }
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->user)->get('/senders')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(2), $count(150));
    }
}
