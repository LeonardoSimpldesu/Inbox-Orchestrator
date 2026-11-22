<?php

namespace Tests\Feature\Subscriptions;

use App\Jobs\UnsubscribeSenderJob;
use App\Models\EmailAccount;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class SubscriptionActionsTest extends SubscriptionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->freezeTime();
    }

    private function unsubscribe(EmailSender $sender, array $data)
    {
        return $this->actingAs($this->user)->from('/subscriptions')->post("/subscriptions/{$sender->id}/unsubscribe", $data);
    }

    public function test_one_click_marks_processing_and_queues_job(): void
    {
        $sender = $this->subscription(['unsubscribe_status' => 'failed', 'unsubscribe_error' => 'timeout']);

        $this->unsubscribe($sender, ['method' => 'one_click', 'cleanup' => 'archive'])
            ->assertRedirect('/subscriptions')
            ->assertSessionHas('success', 'Desinscrição iniciada.');

        $sender->refresh();
        $this->assertSame('processing', $sender->unsubscribe_status);
        $this->assertNull($sender->unsubscribe_error);
        Queue::assertPushed(UnsubscribeSenderJob::class, fn (UnsubscribeSenderJob $job) => $job->senderId === $sender->id && $job->method === 'one_click' && $job->cleanup === 'archive');
    }

    public function test_mailto_queues_job(): void
    {
        $sender = $this->subscription();

        $this->unsubscribe($sender, ['method' => 'mailto', 'cleanup' => 'keep'])->assertSessionHas('success', 'Desinscrição iniciada.');

        Queue::assertPushed(UnsubscribeSenderJob::class, fn (UnsubscribeSenderJob $job) => $job->method === 'mailto' && $job->cleanup === 'keep');
    }

    public function test_page_marks_requested_and_queues_cleanup_only_when_asked(): void
    {
        $sender = $this->subscription();

        $this->unsubscribe($sender, ['method' => 'page', 'cleanup' => 'keep'])->assertSessionHas('success', 'Marcado como desinscrito.');

        $sender->refresh();
        $this->assertSame('requested', $sender->unsubscribe_status);
        $this->assertSame('page', $sender->unsubscribe_method);
        $this->assertSame(now()->timestamp, $sender->unsubscribe_requested_at->timestamp);
        Queue::assertNothingPushed();

        $other = $this->subscription();
        $this->unsubscribe($other, ['method' => 'page', 'cleanup' => 'trash']);
        Queue::assertPushed(UnsubscribeSenderJob::class, fn (UnsubscribeSenderJob $job) => $job->senderId === $other->id && $job->method === 'page' && $job->cleanup === 'trash');
    }

    public function test_rule_creates_retention_rule_and_blocks(): void
    {
        $sender = $this->subscription(['name' => 'Loja X', 'email' => 'promo@lojax.com', 'unsubscribe_url' => null, 'unsubscribe_one_click' => false, 'unsubscribe_mailto' => null, 'total_messages_promotional' => 3]);

        $this->unsubscribe($sender, ['method' => 'rule', 'rule_action' => 'trash', 'cleanup' => 'trash'])->assertSessionHas('success', 'Regra criada.');

        $rule = RetentionRule::sole();
        $this->assertSame($this->account->id, $rule->email_account_id);
        $this->assertSame('Mandar para a lixeira e-mails de Loja X', $rule->name);
        $this->assertSame('sender', $rule->filter_type);
        $this->assertSame('promo@lojax.com', $rule->filter_value);
        $this->assertSame(0, $rule->retention_days);
        $this->assertSame('trash', $rule->action);
        $this->assertTrue($rule->is_active);
        $this->assertSame('blocked', $sender->fresh()->unsubscribe_status);
        $this->assertSame('rule', $sender->fresh()->unsubscribe_method);
        Queue::assertPushed(UnsubscribeSenderJob::class, fn (UnsubscribeSenderJob $job) => $job->method === 'rule' && $job->cleanup === 'trash');

        $nameless = $this->subscription(['name' => null, 'email' => 'x@y.com']);
        $this->unsubscribe($nameless, ['method' => 'rule', 'rule_action' => 'archive', 'cleanup' => 'keep']);
        $this->assertSame('Arquivar e-mails de x@y.com', RetentionRule::latest('id')->first()->name);
    }

    public function test_invalid_input_is_rejected(): void
    {
        $sender = $this->subscription();

        $this->unsubscribe($sender, ['method' => 'magic', 'cleanup' => 'keep'])->assertSessionHasErrors('method');
        $this->unsubscribe($sender, ['method' => 'one_click', 'cleanup' => 'burn'])->assertSessionHasErrors('cleanup');
        $this->unsubscribe($sender, ['method' => 'rule', 'cleanup' => 'keep'])->assertSessionHasErrors('rule_action');
        $this->unsubscribe($sender, ['method' => 'rule', 'rule_action' => 'archive', 'cleanup' => 'trash'])->assertSessionHasErrors('cleanup');
        Queue::assertNothingPushed();
    }

    public function test_unavailable_method_is_a_validation_error(): void
    {
        $sender = $this->subscription(['unsubscribe_one_click' => false, 'unsubscribe_mailto' => null]);

        $this->unsubscribe($sender, ['method' => 'one_click', 'cleanup' => 'keep'])
            ->assertRedirect('/subscriptions')
            ->assertSessionHasErrors(['method' => 'Este remetente não oferece essa forma de desinscrição.']);
        $this->unsubscribe($sender, ['method' => 'mailto', 'cleanup' => 'keep'])->assertSessionHasErrors('method');
        Queue::assertNothingPushed();
    }

    public function test_foreign_or_disconnected_sender_is_404(): void
    {
        $foreign = $this->subscription([], EmailAccount::factory()->create());
        $gone = $this->subscription([], EmailAccount::factory()->for($this->user)->disconnected()->create());

        $this->unsubscribe($foreign, ['method' => 'mailto', 'cleanup' => 'keep'])->assertNotFound();
        $this->unsubscribe($gone, ['method' => 'mailto', 'cleanup' => 'keep'])->assertNotFound();
        $this->actingAs($this->user)->get("/subscriptions/{$foreign->id}/page")->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_needs_reauth_account_is_refused(): void
    {
        $this->account->update(['reauth_required_at' => now()]);
        $sender = $this->subscription();

        $this->unsubscribe($sender, ['method' => 'mailto', 'cleanup' => 'keep'])
            ->assertSessionHas('error', 'Reconecte a conta antes de se desinscrever.');

        $this->assertSame('none', $sender->fresh()->unsubscribe_status);
        Queue::assertNothingPushed();
    }

    public function test_processing_sender_is_not_queued_twice(): void
    {
        $sender = $this->subscription();

        $this->unsubscribe($sender, ['method' => 'mailto', 'cleanup' => 'keep']);
        $this->unsubscribe($sender, ['method' => 'mailto', 'cleanup' => 'keep'])->assertSessionHas('success', 'Desinscrição iniciada.');

        Queue::assertPushed(UnsubscribeSenderJob::class, 1);
    }

    public function test_actions_are_throttled(): void
    {
        $senders = array_map(fn () => $this->subscription(), range(1, 31));

        foreach ($senders as $i => $sender) {
            $response = $this->unsubscribe($sender, ['method' => 'page', 'cleanup' => 'keep']);
        }

        $response->assertStatus(429);
    }

    public function test_page_redirects_to_sender_url_only_for_owner(): void
    {
        $sender = $this->subscription(['unsubscribe_url' => 'https://site.com/unsubscribe?token=abc']);
        $noPage = $this->subscription(['unsubscribe_url' => null, 'unsubscribe_one_click' => false]);

        $this->actingAs($this->user)->get("/subscriptions/{$sender->id}/page")->assertRedirect('https://site.com/unsubscribe?token=abc');
        $this->actingAs($this->user)->get("/subscriptions/{$noPage->id}/page")->assertNotFound();
    }

    public function test_guests_are_redirected(): void
    {
        $sender = $this->subscription();

        $this->post("/subscriptions/{$sender->id}/unsubscribe")->assertRedirect('/login');
        $this->get("/subscriptions/{$sender->id}/page")->assertRedirect('/login');
    }

    public function test_handled_sender_can_be_tried_again(): void
    {
        $sender = $this->subscription(['unsubscribe_status' => 'requested', 'unsubscribe_method' => 'page', 'unsubscribe_requested_at' => now()->subWeek()]);

        $this->unsubscribe($sender, ['method' => 'one_click', 'cleanup' => 'keep'])->assertSessionHas('success', 'Desinscrição iniciada.');

        $this->assertSame('processing', $sender->fresh()->unsubscribe_status);
        Queue::assertPushed(UnsubscribeSenderJob::class, 1);
    }

    public function test_handled_sender_can_go_back_to_pending(): void
    {
        $sender = $this->subscription([
            'unsubscribe_status' => 'blocked', 'unsubscribe_method' => 'rule',
            'unsubscribe_requested_at' => now(), 'unsubscribe_error' => 'cleanup_rate_limited',
        ]);
        $rule = RetentionRule::create(['email_account_id' => $this->account->id, 'name' => 'R', 'filter_type' => 'sender', 'filter_value' => $sender->email, 'retention_days' => 0, 'action' => 'archive', 'is_active' => true]);

        $this->actingAs($this->user)->from('/subscriptions?status=handled')->post("/subscriptions/{$sender->id}/reset")
            ->assertRedirect('/subscriptions?status=handled')
            ->assertSessionHas('success', 'Remetente voltou para Pendentes.');

        $sender->refresh();
        $this->assertSame(['none', null, null, null], [$sender->unsubscribe_status, $sender->unsubscribe_method, $sender->unsubscribe_requested_at, $sender->unsubscribe_error]);
        $this->assertModelExists($rule);
    }

    public function test_processing_sender_cannot_go_back_to_pending(): void
    {
        $sender = $this->subscription(['unsubscribe_status' => 'processing']);

        $this->actingAs($this->user)->post("/subscriptions/{$sender->id}/reset")
            ->assertSessionHas('error', 'Aguarde a desinscrição em andamento terminar.');

        $this->assertSame('processing', $sender->fresh()->unsubscribe_status);
    }

    public function test_reset_is_scoped_to_the_user(): void
    {
        $foreign = $this->subscription(['unsubscribe_status' => 'requested'], EmailAccount::factory()->create());

        $this->actingAs($this->user)->post("/subscriptions/{$foreign->id}/reset")->assertNotFound();
        $this->assertSame('requested', $foreign->fresh()->unsubscribe_status);
    }

    public function test_guests_cannot_reset(): void
    {
        $sender = $this->subscription(['unsubscribe_status' => 'requested']);

        $this->post("/subscriptions/{$sender->id}/reset")->assertRedirect('/login');
    }

    private function bulk(array $data)
    {
        return $this->actingAs($this->user)->from('/subscriptions')->post('/subscriptions/bulk-unsubscribe', $data);
    }

    public function test_bulk_unsubscribe_queues_automatic_methods_only(): void
    {
        $oneClick = $this->subscription();
        $mailto = $this->subscription(['unsubscribe_url' => null, 'unsubscribe_one_click' => false]);
        $pageOnly = $this->subscription(['unsubscribe_one_click' => false, 'unsubscribe_mailto' => null]);
        $ruleOnly = $this->subscription(['unsubscribe_url' => null, 'unsubscribe_one_click' => false, 'unsubscribe_mailto' => null, 'total_messages_promotional' => 2]);
        $busy = $this->subscription(['unsubscribe_status' => 'processing']);
        $foreign = $this->subscription([], EmailAccount::factory()->create());

        $this->bulk(['ids' => [$oneClick->id, $mailto->id, $pageOnly->id, $ruleOnly->id, $busy->id, $foreign->id], 'cleanup' => 'archive'])
            ->assertRedirect('/subscriptions')
            ->assertSessionHas('success', 'Desinscrição iniciada para 2 remetente(s). 2 precisam ser tratados um a um.');

        Queue::assertPushed(UnsubscribeSenderJob::class, 2);
        Queue::assertPushed(UnsubscribeSenderJob::class, fn (UnsubscribeSenderJob $job) => $job->senderId === $oneClick->id && $job->method === 'one_click' && $job->cleanup === 'archive');
        Queue::assertPushed(UnsubscribeSenderJob::class, fn (UnsubscribeSenderJob $job) => $job->senderId === $mailto->id && $job->method === 'mailto');
        $this->assertSame(['processing', 'processing', 'none', 'none'], [$oneClick->fresh()->unsubscribe_status, $mailto->fresh()->unsubscribe_status, $pageOnly->fresh()->unsubscribe_status, $ruleOnly->fresh()->unsubscribe_status]);
        $this->assertSame('none', $foreign->fresh()->unsubscribe_status);
    }

    public function test_bulk_unsubscribe_skips_accounts_needing_reconnection(): void
    {
        $other = EmailAccount::factory()->for($this->user)->needsReauth()->create();
        $ok = $this->subscription();
        $blocked = $this->subscription([], $other);

        $this->bulk(['ids' => [$ok->id, $blocked->id], 'cleanup' => 'keep'])
            ->assertSessionHas('success', 'Desinscrição iniciada para 1 remetente(s). 1 precisam ser tratados um a um.');

        Queue::assertPushed(UnsubscribeSenderJob::class, 1);
    }

    public function test_bulk_unsubscribe_with_nothing_to_do(): void
    {
        $pageOnly = $this->subscription(['unsubscribe_one_click' => false, 'unsubscribe_mailto' => null]);

        $this->bulk(['ids' => [$pageOnly->id], 'cleanup' => 'keep'])
            ->assertSessionHas('error', 'Nenhum dos remetentes selecionados aceita desinscrição automática. Trate-os um a um.');

        Queue::assertNothingPushed();
    }

    public function test_bulk_unsubscribe_validation(): void
    {
        $this->bulk(['ids' => [], 'cleanup' => 'keep'])->assertSessionHasErrors('ids');
        $this->bulk(['ids' => range(1, 101), 'cleanup' => 'keep'])->assertSessionHasErrors('ids');
        $this->bulk(['ids' => ['x'], 'cleanup' => 'keep'])->assertSessionHasErrors('ids.0');
        $this->bulk(['ids' => [1], 'cleanup' => 'burn'])->assertSessionHasErrors('cleanup');
        Queue::assertNothingPushed();
    }

    public function test_bulk_unsubscribe_uses_constant_queries(): void
    {
        $count = function (int $senders): int {
            $ids = array_map(fn () => $this->subscription()->id, range(1, $senders));
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->bulk(['ids' => $ids, 'cleanup' => 'keep']);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(1), $count(40));
    }
}
