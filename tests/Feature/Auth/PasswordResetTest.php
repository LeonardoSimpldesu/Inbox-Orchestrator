<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification as ResetPassword;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    private const GENERIC_STATUS = 'Se o e-mail estiver cadastrado, você receberá um link para redefinir sua senha.';

    private const NEW_PASSWORD = 'uma-senha-bem-longa-2026';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_forgot_password_page_renders(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ForgotPassword')->has('status'));
    }

    public function test_reset_link_is_sent_to_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'user@example.com'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', self::GENERIC_STATUS)
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_e_mail_is_queued_and_links_to_the_app_url_whatever_the_host(): void
    {
        config(['app.url' => 'https://inbox.example.test']);
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->post('http://evil.example/forgot-password', ['email' => 'user@example.com']);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return $notification instanceof ShouldQueue
                && str_starts_with($url, 'https://inbox.example.test/reset-password/')
                && ! str_contains($url, 'evil.example');
        });
    }

    public function test_only_the_app_host_is_trusted(): void
    {
        config(['app.url' => 'https://inbox.example.test']);

        $this->assertContains(TrustHosts::class, app(Kernel::class)->getGlobalMiddleware());
        $this->assertSame(['^inbox\.example\.test$'], app(TrustHosts::class)->hosts());
    }

    public function test_unknown_email_gets_the_same_response(): void
    {
        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', self::GENERIC_STATUS)
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_repeated_request_is_throttled_silently(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->post('/forgot-password', ['email' => 'user@example.com']);
        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'user@example.com'])
            ->assertSessionHas('status', self::GENERIC_STATUS)
            ->assertSessionHasNoErrors();

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_reset_password_page_accepts_a_valid_link(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        $token = Password::createToken($user);

        $this->get("/reset-password/{$token}?email=user%40example.com")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/ResetPassword')
                ->where('linkValid', true)
                ->where('token', $token)
                ->where('email', 'user@example.com')
            );
    }

    public function test_reset_password_page_flags_an_unknown_token_immediately(): void
    {
        User::factory()->create(['email' => 'user@example.com']);

        $this->get('/reset-password/not-a-real-token?email=user%40example.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/ResetPassword')
                ->where('linkValid', false)
                ->where('token', '')
                ->where('email', '')
            );
    }

    public function test_reset_password_page_flags_an_expired_link_immediately(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        $token = Password::createToken($user);

        $this->travel(61)->minutes();

        $this->get("/reset-password/{$token}?email=user%40example.com")
            ->assertInertia(fn (Assert $page) => $page->where('linkValid', false));
    }

    public function test_reset_password_page_flags_a_link_for_an_unknown_email_immediately(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        $token = Password::createToken($user);

        $this->get("/reset-password/{$token}?email=nobody%40example.com")
            ->assertInertia(fn (Assert $page) => $page->where('linkValid', false)->where('email', ''));
    }

    public function test_reset_password_page_flags_a_used_link_immediately(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        $token = Password::createToken($user);
        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'user@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $this->get("/reset-password/{$token}?email=user%40example.com")
            ->assertInertia(fn (Assert $page) => $page->where('linkValid', false));
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'user@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/login')->assertSessionHasNoErrors();

        $this->post('/login', ['email' => 'user@example.com', 'password' => self::NEW_PASSWORD]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_reset_token_cannot_be_reused(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        $token = Password::createToken($user);
        $payload = [
            'token' => $token,
            'email' => 'user@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];

        $this->post('/reset-password', $payload)->assertSessionHasNoErrors();
        $this->post('/reset-password', [...$payload, 'password' => 'outra-senha-bem-longa', 'password_confirmation' => 'outra-senha-bem-longa'])
            ->assertSessionHasErrors(['email' => 'Este link de redefinição de senha é inválido ou expirou.']);
    }

    public function test_reset_with_an_email_other_than_the_links_reports_an_invalid_link(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'someone-else@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors(['email' => 'Este link de redefinição de senha é inválido ou expirou.']);

        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        $token = Password::createToken($user);

        $this->travel(61)->minutes();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'user@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('email');
    }

    public function test_password_shorter_than_12_characters_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'user@example.com',
            'password' => 'curta-123',
            'password_confirmation' => 'curta-123',
        ])->assertSessionHasErrors(['password' => 'O campo senha deve ter pelo menos 12 caracteres.']);
    }

    public function test_reset_email_is_in_portuguese(): void
    {
        $user = User::factory()->create();

        $mail = (new ResetPassword('token'))->toMail($user);

        $this->assertSame('Redefinição de senha', $mail->subject);
        $this->assertSame('Redefinir senha', $mail->actionText);
        $this->assertContains('Este link de redefinição de senha expira em 60 minutos.', $mail->outroLines);
    }

    public function test_password_change_ends_other_open_sessions(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);

        // A session opened elsewhere (e.g. a stolen or forgotten device).
        $this->post('/login', ['email' => 'user@example.com', 'password' => 'password']);
        $this->get('/')->assertOk();

        // The password is reset from another device.
        $user->forceFill(['password' => self::NEW_PASSWORD])->save();
        Auth::forgetGuards(); // next request loads the user fresh, as in production

        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_reset_link_requests_are_limited_per_ip_with_the_same_answer(): void
    {
        $users = User::factory()->count(6)->create();

        foreach ($users as $user) {
            $this->from('/forgot-password')
                ->post('/forgot-password', ['email' => $user->email])
                ->assertRedirect('/forgot-password')
                ->assertSessionHas('status', self::GENERIC_STATUS)
                ->assertSessionHasNoErrors();
        }

        Notification::assertSentTimes(ResetPassword::class, 5);
        Notification::assertNotSentTo($users->last(), ResetPassword::class);
    }
}
