<?php

namespace Tests\Feature\Subscriptions;

use App\Models\EmailAccount;
use App\Models\EmailMessageMeta;
use App\Models\EmailSender;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

class SubscriptionListTest extends SubscriptionTestCase
{
    public function test_guests_are_redirected(): void
    {
        $this->get('/subscriptions')->assertRedirect('/login');
    }

    public function test_lists_only_subscriptions_of_user(): void
    {
        $mine = $this->subscription(['email' => 'mine@news.com']);
        $this->subscription(['email' => 'friend@x.com', 'unsubscribe_url' => null, 'unsubscribe_one_click' => false, 'unsubscribe_mailto' => null]);
        $this->subscription(['email' => 'other@news.com'], EmailAccount::factory()->create());
        $this->subscription(['email' => 'gone@news.com'], EmailAccount::factory()->for($this->user)->disconnected()->create());

        $this->actingAs($this->user)->get('/subscriptions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Index')
                ->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.id', $mine->id)
                ->where('subscriptions.data.0.email', 'mine@news.com')
                ->where('subscriptions.data.0.account_email', 'me@gmail.com')
                ->where('subscriptions.data.0.total', 10)
                ->where('subscriptions.data.0.unread', 4)
                ->where('subscriptions.data.0.status', 'none')
                ->where('subscriptions.data.0.options', ['one_click' => true, 'mailto' => true, 'page' => true])
                ->has('accounts', 1)
                ->where('accounts.0.account_email', 'me@gmail.com')
                ->where('filters', ['account' => null, 'status' => 'pending', 'q' => ''])
            );
    }

    public function test_paginates_by_100_ordered_by_volume(): void
    {
        for ($i = 1; $i <= 105; $i++) {
            $this->subscription(['total_messages_received' => $i]);
        }

        $this->actingAs($this->user)->get('/subscriptions')->assertInertia(fn (Assert $page) => $page
            ->has('subscriptions.data', 100)
            ->where('subscriptions.data.0.total', 105)
            ->where('subscriptions.data.99.total', 6)
            ->where('subscriptions.last_page', 2)
        );

        $this->actingAs($this->user)->get('/subscriptions?page=2')->assertInertia(fn (Assert $page) => $page
            ->has('subscriptions.data', 5)
            ->where('subscriptions.data.4.total', 1)
        );
    }

    public function test_account_filter_ignores_foreign_account(): void
    {
        $second = EmailAccount::factory()->for($this->user)->create(['account_email' => 'two@gmail.com']);
        $this->subscription(['email' => 'a@news.com']);
        $this->subscription(['email' => 'b@news.com'], $second);
        $foreign = EmailAccount::factory()->create();

        $this->actingAs($this->user)->get("/subscriptions?account={$second->id}")->assertInertia(fn (Assert $page) => $page
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.email', 'b@news.com')
            ->where('filters.account', $second->id)
        );

        $this->actingAs($this->user)->get("/subscriptions?account={$foreign->id}")->assertInertia(fn (Assert $page) => $page
            ->has('subscriptions.data', 2)
            ->where('filters.account', null)
        );
    }

    public function test_tabs_and_counts(): void
    {
        $this->subscription(['unsubscribe_status' => 'none']);
        $this->subscription(['unsubscribe_status' => 'failed', 'unsubscribe_error' => 'http_404']);
        $this->subscription(['unsubscribe_status' => 'requested']);
        $this->subscription(['unsubscribe_status' => 'blocked']);
        $this->subscription(['unsubscribe_status' => 'blocked']);

        $this->actingAs($this->user)->get('/subscriptions')->assertInertia(fn (Assert $page) => $page
            ->has('subscriptions.data', 2)
            ->where('counts', ['pending' => 2, 'handled' => 3])
        );

        $this->actingAs($this->user)->get('/subscriptions?status=handled')->assertInertia(fn (Assert $page) => $page
            ->has('subscriptions.data', 3)
            ->where('filters.status', 'handled')
        );

        $this->actingAs($this->user)->get('/subscriptions?status=bogus')->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'pending')
        );
    }

    public function test_emails_after_request_count(): void
    {
        $sender = $this->subscription(['unsubscribe_status' => 'requested', 'unsubscribe_requested_at' => '2026-11-10 12:00:00']);
        foreach (['2026-11-09 12:00:00', '2026-11-11 12:00:00', '2026-11-12 12:00:00'] as $i => $date) {
            EmailMessageMeta::create([
                'email_account_id' => $this->account->id,
                'provider_message_id' => "m{$i}",
                'provider_thread_id' => "t{$i}",
                'email_sender_id' => $sender->id,
                'sender_email' => $sender->email,
                'sender_domain' => $sender->domain,
                'labels' => ['INBOX'],
                'received_at' => $date,
            ]);
        }

        $this->actingAs($this->user)->get('/subscriptions?status=handled')->assertInertia(fn (Assert $page) => $page
            ->where('subscriptions.data.0.emails_after_request', 2)
            ->where('subscriptions.data.0.requested_at', '2026-11-10T12:00:00+00:00')
        );
    }

    public function test_props_never_contain_unsubscribe_targets(): void
    {
        $sender = $this->subscription();

        $response = $this->actingAs($this->user)->get('/subscriptions');

        $response->assertDontSee($sender->unsubscribe_url, escape: false);
        $response->assertDontSee('secret', escape: false);
        $response->assertDontSee('mailto:', escape: false);
    }

    public function test_gmail_url_is_encoded(): void
    {
        $this->subscription(['email' => 'news+promo@site.com']);

        $this->actingAs($this->user)->get('/subscriptions')->assertInertia(fn (Assert $page) => $page
            ->where('subscriptions.data.0.gmail_url', 'https://mail.google.com/mail/u/?authuser=me%40gmail.com#search/from:news%2Bpromo%40site.com')
        );
    }

    public function test_list_uses_constant_queries(): void
    {
        $count = function (int $senders): int {
            EmailSender::query()->delete();
            for ($i = 0; $i < $senders; $i++) {
                $this->subscription();
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->user)->get('/subscriptions')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(1), $count(150));
    }

    public function test_search_matches_name_email_or_domain(): void
    {
        $this->subscription(['name' => 'Loja Azul', 'email' => 'promo@azul.com', 'domain' => 'azul.com']);
        $this->subscription(['name' => 'Jornal', 'email' => 'news@jornal.com.br', 'domain' => 'jornal.com.br']);
        $this->subscription(['name' => 'Outro', 'email' => 'oi@verde.com', 'domain' => 'verde.com']);

        $names = fn (string $query) => collect($this->actingAs($this->user)->get('/subscriptions?q='.urlencode($query))
            ->viewData('page')['props']['subscriptions']['data'])->pluck('name')->sort()->values()->all();

        $this->assertSame(['Loja Azul'], $names('azul'));
        $this->assertSame(['Jornal'], $names('NEWS@'));
        $this->assertSame(['Jornal'], $names('com.br'));
        $this->assertSame([], $names('%'));
        $this->assertSame(['Jornal', 'Loja Azul', 'Outro'], $names('  '));

        $this->actingAs($this->user)->get('/subscriptions?q=azul')->assertInertia(fn (Assert $page) => $page
            ->where('filters.q', 'azul')
            ->where('counts.pending', 1)
        );
    }
}
