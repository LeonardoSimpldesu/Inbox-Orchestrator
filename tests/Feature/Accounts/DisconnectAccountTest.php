<?php

namespace Tests\Feature\Accounts;

use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class DisconnectAccountTest extends AccountsTestCase
{
    public function test_disconnect_revokes_and_soft_deletes(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create(['account_email' => 'bye@gmail.com']);

        $this->actingAs($user)->delete("/accounts/{$account->id}")
            ->assertRedirect('/accounts')
            ->assertSessionHas('success', 'Conta bye@gmail.com desconectada. Os dados ficam guardados se você reconectar.');

        $this->assertSame([$account->id], $this->provider->revoked);
        $this->assertSoftDeleted($account);
    }

    public function test_disconnect_still_happens_when_revoke_fails(): void
    {
        Log::spy();
        $this->provider->revokeResult = false;
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();

        $this->actingAs($user)->delete("/accounts/{$account->id}")->assertSessionHas('success');

        $this->assertSoftDeleted($account);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($account) {
            $this->assertSame(['account_id' => $account->id], $context);

            return true;
        });
    }

    public function test_cannot_disconnect_other_users_or_disconnected_account(): void
    {
        $user = User::factory()->create();
        $foreign = EmailAccount::factory()->create();
        $gone = EmailAccount::factory()->for($user)->disconnected()->create();

        $this->actingAs($user)->delete("/accounts/{$foreign->id}")->assertNotFound();
        $this->actingAs($user)->delete("/accounts/{$gone->id}")->assertNotFound();
        $this->actingAs($user)->delete('/accounts/not-a-number')->assertNotFound();

        $this->assertSame([], $this->provider->revoked);
        $this->assertNotSoftDeleted($foreign);
    }
}
