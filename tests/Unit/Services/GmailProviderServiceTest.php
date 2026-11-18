<?php

namespace Tests\Unit\Services;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\OAuthExchangeFailed;
use App\Exceptions\ReauthorizationRequired;
use App\Models\EmailAccount;
use App\Services\Providers\GmailProviderService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class GmailProviderServiceTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'client-id.apps.googleusercontent.com',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect' => 'http://localhost:8000/accounts/google/callback',
        ]);
    }

    /**
     * @param  array<int, Response>  $responses
     */
    private function service(array $responses = []): GmailProviderService
    {
        $mock = new MockHandler($responses);
        $recorder = function (RequestInterface $request, array $options) use ($mock) {
            $this->history[] = ['request' => $request];

            return $mock($request, $options);
        };
        $stack = HandlerStack::create($recorder);

        return new GmailProviderService(new Client(['handler' => $stack, 'http_errors' => false]));
    }

    private function jsonResponse(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function tokenResponse(array $overrides = []): Response
    {
        return $this->jsonResponse(array_merge([
            'access_token' => 'new-access-token',
            'expires_in' => 3599,
            'refresh_token' => 'new-refresh-token',
            'scope' => implode(' ', GmailProviderService::SCOPES),
            'token_type' => 'Bearer',
        ], $overrides));
    }

    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_authorization_url_contains_scopes_state_and_offline_consent(): void
    {
        $query = $this->queryOf($this->service()->authorizationUrl('state-123'));

        $this->assertSame('client-id.apps.googleusercontent.com', $query['client_id']);
        $this->assertSame('http://localhost:8000/accounts/google/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('state-123', $query['state']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('false', $query['include_granted_scopes']);
        $this->assertSame(
            'https://www.googleapis.com/auth/gmail.modify https://www.googleapis.com/auth/gmail.send',
            $query['scope'],
        );
        $this->assertArrayNotHasKey('login_hint', $query);
    }

    public function test_authorization_url_includes_login_hint(): void
    {
        $query = $this->queryOf($this->service()->authorizationUrl('s', 'me@gmail.com'));

        $this->assertSame('me@gmail.com', $query['login_hint']);
    }

    public function test_exchange_code_returns_grant_with_profile_email(): void
    {
        $this->freezeTime();
        $grant = $this->service([
            $this->tokenResponse(),
            $this->jsonResponse(['emailAddress' => 'Foo@Gmail.com', 'historyId' => '1']),
        ])->exchangeCode('auth-code');

        $this->assertSame('new-access-token', $grant->accessToken);
        $this->assertSame('new-refresh-token', $grant->refreshToken);
        $this->assertSame('foo@gmail.com', $grant->email);
        $this->assertSame(now()->addSeconds(3599)->timestamp, $grant->expiresAt->timestamp);
        $this->assertTrue($grant->hasScopes(GmailProviderService::SCOPES));
        $this->assertFalse($grant->hasScopes([...GmailProviderService::SCOPES, 'https://www.googleapis.com/auth/drive']));

        $this->assertStringContainsString('code=auth-code', (string) $this->history[0]['request']->getBody());
        $this->assertSame('Bearer new-access-token', $this->history[1]['request']->getHeaderLine('Authorization'));
        $this->assertStringEndsWith('/gmail/v1/users/me/profile', $this->history[1]['request']->getUri()->getPath());
    }

    public function test_exchange_code_without_refresh_token(): void
    {
        $grant = $this->service([
            $this->tokenResponse(['refresh_token' => null]),
            $this->jsonResponse(['emailAddress' => 'a@gmail.com']),
        ])->exchangeCode('auth-code');

        $this->assertNull($grant->refreshToken);
    }

    public function test_exchange_code_error_throws_without_leaking_code(): void
    {
        try {
            $this->service([$this->jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Bad Request'], 400)])
                ->exchangeCode('secret-auth-code');
            $this->fail('Expected OAuthExchangeFailed');
        } catch (OAuthExchangeFailed $e) {
            $this->assertSame('invalid_grant', $e->getMessage());
            $this->assertStringNotContainsString('secret-auth-code', $e->getMessage());
        }
    }

    public function test_exchange_code_network_failure_throws(): void
    {
        $this->expectException(OAuthExchangeFailed::class);
        $this->expectExceptionMessage('request_failed');

        $this->service([new ConnectException('down', new Request('POST', 'x'))])
            ->exchangeCode('auth-code');
    }

    public function test_exchange_code_profile_failure_throws(): void
    {
        $this->expectException(OAuthExchangeFailed::class);
        $this->expectExceptionMessage('profile_unavailable');

        $this->service([
            $this->tokenResponse(),
            $this->jsonResponse(['error' => ['code' => 403, 'message' => 'Gmail API has not been used']], 403),
        ])->exchangeCode('auth-code');
    }

    public function test_refresh_skipped_when_token_still_valid(): void
    {
        $account = EmailAccount::factory()->create(['access_token' => 'current', 'token_expires_at' => now()->addMinutes(10)]);

        $this->assertSame('current', $this->service()->refreshAccessToken($account));
        $this->assertCount(0, $this->history);
    }

    public function test_refresh_stores_new_access_token_and_expiry(): void
    {
        $this->freezeTime();
        $account = EmailAccount::factory()->create(['refresh_token' => 'stored-refresh', 'token_expires_at' => now()->addSeconds(30)]);

        $token = $this->service([$this->tokenResponse(['refresh_token' => null])])->refreshAccessToken($account);

        $account->refresh();
        $this->assertSame('new-access-token', $token);
        $this->assertSame('new-access-token', $account->access_token);
        $this->assertSame(now()->addSeconds(3599)->timestamp, $account->token_expires_at->timestamp);
        $this->assertSame('stored-refresh', $account->refresh_token);
        $this->assertStringContainsString('refresh_token=stored-refresh', (string) $this->history[0]['request']->getBody());
    }

    public function test_refresh_stores_rotated_refresh_token(): void
    {
        $account = EmailAccount::factory()->create(['token_expires_at' => now()->subMinute()]);

        $this->service([$this->tokenResponse(['refresh_token' => 'rotated-refresh'])])->refreshAccessToken($account);

        $this->assertSame('rotated-refresh', $account->fresh()->refresh_token);
    }

    public function test_refresh_when_expiry_unknown(): void
    {
        $account = EmailAccount::factory()->create(['token_expires_at' => null]);

        $this->assertSame('new-access-token', $this->service([$this->tokenResponse()])->refreshAccessToken($account));
    }

    public function test_invalid_grant_marks_account_and_throws(): void
    {
        $account = EmailAccount::factory()->create(['token_expires_at' => now()->subMinute()]);

        try {
            $this->service([$this->jsonResponse(['error' => 'invalid_grant'], 400)])->refreshAccessToken($account);
            $this->fail('Expected ReauthorizationRequired');
        } catch (ReauthorizationRequired) {
            $this->assertNotNull($account->fresh()->reauth_required_at);
        }
    }

    public function test_other_refresh_errors_throw_without_marking_account(): void
    {
        $account = EmailAccount::factory()->create(['token_expires_at' => now()->subMinute()]);

        try {
            $this->service([$this->jsonResponse(['error' => 'internal_failure'], 500)])->refreshAccessToken($account);
            $this->fail('Expected OAuthExchangeFailed');
        } catch (OAuthExchangeFailed $e) {
            $this->assertSame('internal_failure', $e->getMessage());
            $this->assertNull($account->fresh()->reauth_required_at);
        }
    }

    public function test_revoke_posts_refresh_token(): void
    {
        $account = EmailAccount::factory()->create(['refresh_token' => 'refresh-to-revoke']);

        $this->assertTrue($this->service([new Response(200)])->revoke($account));
        $request = $this->history[0]['request'];
        $this->assertSame('oauth2.googleapis.com', $request->getUri()->getHost());
        $this->assertStringContainsString('token=refresh-to-revoke', (string) $request->getBody());
    }

    public function test_revoke_failure_returns_false(): void
    {
        $account = EmailAccount::factory()->create();

        $this->assertFalse($this->service([new Response(400)])->revoke($account));
        $this->assertFalse($this->service([new ConnectException('down', new Request('POST', 'x'))])->revoke($account));
    }

    public function test_default_constructor_builds_a_working_service(): void
    {
        $this->assertStringStartsWith('https://accounts.google.com/', app(EmailProviderInterface::class)->authorizationUrl('s'));
    }
}
