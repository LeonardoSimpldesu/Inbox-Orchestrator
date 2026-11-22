<?php

namespace Tests\Feature\Senders;

use App\Exceptions\ProviderUnavailable;
use App\Jobs\ApplySenderLabelJob;
use App\Models\EmailAccount;
use App\Models\RetentionRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

class SenderCategorizeTest extends SendersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function categorizeRequest(array $data)
    {
        return $this->actingAs($this->user)->from('/senders')->post('/senders/categorize', $data);
    }

    public function test_categorize_creates_rule_and_queues_job(): void
    {
        $a = $this->sender();
        $b = $this->sender();

        $this->categorizeRequest(['ids' => [$a->id, $b->id], 'label_id' => 'Label_1'])
            ->assertRedirect('/senders')
            ->assertSessionHas('success', 'Categoria aplicada a 2 remetente(s).');

        $this->assertSame(2, RetentionRule::where('action', 'add_label')->where('target_label', 'Label_1')->count());
        $this->assertNotNull($a->fresh()->label_applying_at);
        Queue::assertPushed(ApplySenderLabelJob::class, fn (ApplySenderLabelJob $job) => $job->senderId === $a->id && $job->accountId === $this->account->id && $job->newLabel === 'Label_1' && $job->oldLabel === null);
        Queue::assertPushed(ApplySenderLabelJob::class, 2);
    }

    public function test_change_and_remove_category(): void
    {
        $sender = $this->sender();
        $this->categorize($sender, 'Label_1');

        $this->categorizeRequest(['ids' => [$sender->id], 'label_id' => 'Label_2']);
        Queue::assertPushed(ApplySenderLabelJob::class, fn (ApplySenderLabelJob $job) => $job->newLabel === 'Label_2' && $job->oldLabel === 'Label_1');

        $this->categorizeRequest(['ids' => [$sender->id], 'label_id' => null])->assertSessionHas('success', 'Categoria removida de 1 remetente(s).');
        Queue::assertPushed(ApplySenderLabelJob::class, fn (ApplySenderLabelJob $job) => $job->newLabel === null && $job->oldLabel === 'Label_2');
        $this->assertSame(0, RetentionRule::count());
    }

    public function test_same_label_queues_nothing(): void
    {
        $sender = $this->sender();
        $this->categorize($sender, 'Label_1');

        $this->categorizeRequest(['ids' => [$sender->id], 'label_id' => 'Label_1'])->assertSessionHas('success');

        Queue::assertNothingPushed();
        $this->assertNull($sender->fresh()->label_applying_at);
    }

    public function test_categorize_requires_single_account(): void
    {
        $a = $this->sender();
        $b = $this->sender([], EmailAccount::factory()->for($this->user)->create());

        $this->categorizeRequest(['ids' => [$a->id, $b->id], 'label_id' => 'Label_1'])
            ->assertSessionHas('error', 'Selecione remetentes de uma mesma conta para escolher a categoria.');

        $this->assertSame(0, RetentionRule::count());
        Queue::assertNothingPushed();
    }

    public function test_label_must_belong_to_account(): void
    {
        $this->categorizeRequest(['ids' => [$this->sender()->id], 'label_id' => 'Label_99'])
            ->assertSessionHas('error', 'Escolha um marcador desta conta.');

        $this->provider->failNext['listLabels'] = new ProviderUnavailable('rate_limited');
        $this->categorizeRequest(['ids' => [$this->sender()->id], 'label_id' => 'Label_1'])
            ->assertSessionHas('error', 'Não foi possível carregar os marcadores do Gmail. Tente de novo.');

        $this->assertSame(0, RetentionRule::count());
    }

    public function test_needs_reauth_refused(): void
    {
        $this->account->update(['reauth_required_at' => now()]);

        $this->categorizeRequest(['ids' => [$this->sender()->id], 'label_id' => 'Label_1'])
            ->assertSessionHas('error', 'Reconecte a conta antes de organizar os remetentes.');
    }

    public function test_foreign_senders_are_ignored(): void
    {
        $foreign = $this->sender([], EmailAccount::factory()->create());

        $this->categorizeRequest(['ids' => [$foreign->id], 'label_id' => 'Label_1'])->assertSessionHas('error', 'Nenhum remetente encontrado.');

        $this->assertSame(0, RetentionRule::count());
    }

    public function test_category_follows_the_rule(): void
    {
        $sender = $this->sender();
        $rule = $this->categorize($sender, 'Label_1');

        $this->actingAs($this->user)->delete("/retention-rules/{$rule->id}");

        $this->actingAs($this->user)->get('/senders')->assertInertia(fn (Assert $page) => $page->where('senders.data.0.category', null));
    }

    public function test_validation_and_throttle(): void
    {
        $this->categorizeRequest(['ids' => [], 'label_id' => 'Label_1'])->assertSessionHasErrors('ids');
        $this->categorizeRequest(['ids' => range(1, 101), 'label_id' => 'Label_1'])->assertSessionHasErrors('ids');
        $this->categorizeRequest(['ids' => ['x'], 'label_id' => 'Label_1'])->assertSessionHasErrors('ids.0');

        for ($i = 0; $i < 28; $i++) {
            $response = $this->categorizeRequest(['ids' => [1], 'label_id' => null]);
        }
        $response->assertStatus(429);
    }

    public function test_categorize_uses_constant_queries(): void
    {
        $count = function (int $senders): int {
            $ids = collect(range(1, $senders))->map(fn () => $this->sender()->id)->all();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->categorizeRequest(['ids' => $ids, 'label_id' => 'Label_1']);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(1), $count(40));
    }
}
