<?php

namespace Tests\Unit\Services;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\HistoryExpired;
use App\Exceptions\MissingRequiredScopes;
use App\Exceptions\OAuthExchangeFailed;
use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use App\Models\EmailAccount;
use App\Services\Providers\GmailProviderService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Sleep;
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
        $this->assertSame(GmailProviderService::SCOPES, $grant->grantedScopes);

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

    public function test_exchange_code_with_missing_scopes_throws_before_reading_profile(): void
    {
        try {
            $this->service([$this->tokenResponse(['scope' => 'https://www.googleapis.com/auth/gmail.send'])])
                ->exchangeCode('auth-code');
            $this->fail('Expected MissingRequiredScopes');
        } catch (MissingRequiredScopes $e) {
            $this->assertSame('missing_scopes', $e->getMessage());
            $this->assertCount(1, $this->history);
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

    private function connectedAccount(): EmailAccount
    {
        return EmailAccount::factory()->create(['access_token' => 'valid-access', 'token_expires_at' => now()->addHour()]);
    }

    /**
     * @param  array<string, array{status: int, body: array<string, mixed>}>  $parts  keyed by message id
     */
    private function batchResponse(array $parts): Response
    {
        $boundary = 'batch_test_boundary';
        $body = '';

        foreach ($parts as $id => $part) {
            $reason = $part['status'] === 200 ? 'OK' : 'Error';
            $body .= "--{$boundary}\r\nContent-Type: application/http\r\nContent-ID: response-{$id}\r\n\r\n"
                ."HTTP/1.1 {$part['status']} {$reason}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
                .json_encode($part['body'])."\r\n";
        }

        return new Response(200, ['Content-Type' => "multipart/mixed; boundary={$boundary}"], $body."--{$boundary}--");
    }

    private function gmailMessage(string $id, array $headers = [], array $labels = ['INBOX', 'UNREAD']): array
    {
        return [
            'id' => $id,
            'threadId' => 'thread-'.$id,
            'labelIds' => $labels,
            'internalDate' => '1731900000000',
            'payload' => ['headers' => array_map(fn ($name, $value) => ['name' => $name, 'value' => $value], array_keys($headers), $headers)],
        ];
    }

    public function test_current_history_id_reads_profile(): void
    {
        $id = $this->service([$this->jsonResponse(['emailAddress' => 'a@gmail.com', 'historyId' => '4242'])])
            ->currentHistoryId($this->connectedAccount());

        $this->assertSame('4242', $id);
        $this->assertSame('Bearer valid-access', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    public function test_list_message_ids_sends_query_and_paginates(): void
    {
        $page = $this->service([$this->jsonResponse([
            'messages' => [['id' => 'm1', 'threadId' => 't1'], ['id' => 'm2', 'threadId' => 't2']],
            'nextPageToken' => 'next-token',
        ])])->listMessageIds($this->connectedAccount(), 'after:1700000000', 'prev-token', 100);

        $this->assertSame(['m1', 'm2'], $page->ids);
        $this->assertSame('next-token', $page->nextPageToken);

        $query = $this->queryOf((string) $this->history[0]['request']->getUri());
        $this->assertSame('after:1700000000', $query['q']);
        $this->assertSame('prev-token', $query['pageToken']);
        $this->assertSame('100', $query['maxResults']);
        $this->assertArrayNotHasKey('includeSpamTrash', $query);
    }

    public function test_list_message_ids_empty_page(): void
    {
        $page = $this->service([$this->jsonResponse(['resultSizeEstimate' => 0])])
            ->listMessageIds($this->connectedAccount(), 'before:1');

        $this->assertSame([], $page->ids);
        $this->assertNull($page->nextPageToken);
    }

    public function test_fetch_metadata_uses_one_batch_request_per_25_ids(): void
    {
        $ids = array_map(fn ($i) => "m{$i}", range(1, 150));
        $chunks = array_map(fn ($chunk) => array_fill_keys($chunk, null), array_chunk($ids, 25));
        $part = fn ($id) => ['status' => 200, 'body' => $this->gmailMessage($id, [
            'From' => '"News" <news@site.com>',
            'subject' => 'Oferta',
            'List-Unsubscribe' => '<https://site.com/u>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ])];

        $messages = $this->service(array_map(
            fn ($chunk) => $this->batchResponse(array_combine(array_keys($chunk), array_map($part, array_keys($chunk)))),
            $chunks,
        ))->fetchMetadata($this->connectedAccount(), $ids);

        $this->assertCount(6, $this->history);
        $this->assertSame('https://gmail.googleapis.com/batch', (string) $this->history[0]['request']->getUri());
        $this->assertStringContainsString('format=metadata', (string) $this->history[0]['request']->getBody());
        $this->assertStringContainsString('metadataHeaders=List-Unsubscribe-Post', (string) $this->history[0]['request']->getBody());
        $this->assertCount(150, $messages);

        $message = $messages[0];
        $this->assertSame('m1', $message->id);
        $this->assertSame('thread-m1', $message->threadId);
        $this->assertSame(['INBOX', 'UNREAD'], $message->labelIds);
        $this->assertSame(1731900000, $message->internalDate->timestamp);
        $this->assertSame('"News" <news@site.com>', $message->from);
        $this->assertSame('Oferta', $message->subject);
        $this->assertSame('<https://site.com/u>', $message->listUnsubscribe);
        $this->assertSame('List-Unsubscribe=One-Click', $message->listUnsubscribePost);
    }

    public function test_fetch_metadata_omits_not_found_items(): void
    {
        $messages = $this->service([$this->batchResponse([
            'gone' => ['status' => 404, 'body' => ['error' => ['code' => 404, 'message' => 'Not Found']]],
            'kept' => ['status' => 200, 'body' => $this->gmailMessage('kept')],
        ])])->fetchMetadata($this->connectedAccount(), ['gone', 'kept']);

        $this->assertSame(['kept'], array_map(fn ($m) => $m->id, $messages));
        $this->assertNull($messages[0]->from);
    }

    public function test_fetch_metadata_with_no_ids_makes_no_request(): void
    {
        $this->assertSame([], $this->service()->fetchMetadata($this->connectedAccount(), []));
        $this->assertCount(0, $this->history);
    }

    public function test_rate_limited_items_are_retried_after_a_pause(): void
    {
        Sleep::fake();
        $limited = ['status' => 429, 'body' => ['error' => ['code' => 429, 'message' => 'Too many concurrent requests for user']]];

        $messages = $this->service([
            $this->batchResponse(['m1' => $limited, 'm2' => ['status' => 200, 'body' => $this->gmailMessage('m2')]]),
            $this->batchResponse(['m1' => $limited]),
            $this->batchResponse(['m1' => ['status' => 200, 'body' => $this->gmailMessage('m1')]]),
        ])->fetchMetadata($this->connectedAccount(), ['m1', 'm2']);

        $this->assertSame(['m1', 'm2'], array_map(fn ($m) => $m->id, $messages));
        $this->assertCount(3, $this->history);
        $this->assertStringNotContainsString('/m2', (string) $this->history[1]['request']->getBody());
        Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(2)->seconds()]);
    }

    public function test_items_still_rate_limited_after_retries_throw_provider_unavailable(): void
    {
        Sleep::fake();
        $limited = ['status' => 429, 'body' => ['error' => ['code' => 429, 'message' => 'Too many']]];

        try {
            $this->service(array_fill(0, 4, $this->batchResponse(['m1' => $limited])))
                ->fetchMetadata($this->connectedAccount(), ['m1']);
            $this->fail('Expected ProviderUnavailable');
        } catch (ProviderUnavailable $e) {
            $this->assertSame('rate_limited', $e->getMessage());
            Sleep::assertSleptTimes(3);
        }
    }

    public function test_quota_403_items_are_retried_like_429(): void
    {
        Sleep::fake();
        $quota = ['status' => 403, 'body' => ['error' => ['code' => 403, 'message' => "Quota exceeded for quota metric 'Total Query Cost'", 'errors' => [['reason' => 'rateLimitExceeded', 'domain' => 'usageLimits']]]]];

        $messages = $this->service([
            $this->batchResponse(['m1' => $quota]),
            $this->batchResponse(['m1' => ['status' => 200, 'body' => $this->gmailMessage('m1')]]),
        ])->fetchMetadata($this->connectedAccount(), ['m1']);

        $this->assertCount(1, $messages);
        Sleep::assertSleptTimes(1);
    }

    public function test_quota_403_on_a_call_is_reported_as_rate_limited(): void
    {
        $this->expectException(ProviderUnavailable::class);
        $this->expectExceptionMessage('rate_limited');

        $this->service([$this->jsonResponse(['error' => ['code' => 403, 'message' => 'Quota exceeded', 'errors' => [['reason' => 'userRateLimitExceeded']]]], 403)])
            ->listMessageIds($this->connectedAccount(), 'after:1');
    }

    public function test_server_error_on_a_call_is_reported_with_its_code(): void
    {
        $this->expectException(ProviderUnavailable::class);
        $this->expectExceptionMessage('http_500');

        $this->service([$this->jsonResponse(['error' => ['code' => 500, 'message' => 'Backend Error']], 500)])
            ->listMessageIds($this->connectedAccount(), 'after:1');
    }

    public function test_throttles_to_configured_units_per_minute(): void
    {
        Sleep::fake(syncWithCarbon: true);
        config(['services.google.quota_units_per_minute' => 300]);
        $account = $this->connectedAccount();
        $ok = fn (array $ids) => $this->batchResponse(array_combine($ids, array_map(fn ($id) => ['status' => 200, 'body' => $this->gmailMessage($id)], $ids)));
        $ids = array_map(fn ($i) => "m{$i}", range(1, 75));

        $this->service([$ok(array_slice($ids, 0, 25)), $ok(array_slice($ids, 25, 25)), $ok(array_slice($ids, 50, 25))])
            ->fetchMetadata($account, $ids);

        Sleep::assertSleptTimes(1);
        $this->assertCount(3, $this->history);
    }

    public function test_quota_budget_is_per_account(): void
    {
        Sleep::fake();
        config(['services.google.quota_units_per_minute' => 10]);

        $this->service([$this->jsonResponse(['messages' => []]), $this->jsonResponse(['messages' => []])])
            ->listMessageIds($this->connectedAccount(), 'after:1');
        $this->service([$this->jsonResponse(['messages' => []])])
            ->listMessageIds($this->connectedAccount(), 'after:1');

        Sleep::assertNeverSlept();
    }

    public function test_other_item_errors_throw_without_retrying(): void
    {
        Sleep::fake();
        $this->expectException(ProviderUnavailable::class);
        $this->expectExceptionMessage('http_500');

        try {
            $this->service([$this->batchResponse(['m1' => ['status' => 500, 'body' => ['error' => ['code' => 500, 'message' => 'Backend']]]])])
                ->fetchMetadata($this->connectedAccount(), ['m1']);
        } finally {
            Sleep::assertNeverSlept();
        }
    }

    public function test_list_changes_collects_changed_and_deleted_ids(): void
    {
        $page = $this->service([$this->jsonResponse([
            'history' => [
                ['id' => '11', 'messagesAdded' => [['message' => ['id' => 'new1']]]],
                ['id' => '12', 'labelsAdded' => [['message' => ['id' => 'lab1'], 'labelIds' => ['TRASH']]]],
                ['id' => '13', 'labelsRemoved' => [['message' => ['id' => 'lab2'], 'labelIds' => ['UNREAD']]]],
                ['id' => '14', 'messagesAdded' => [['message' => ['id' => 'tmp']]], 'messagesDeleted' => [['message' => ['id' => 'tmp']], ['message' => ['id' => 'old']]]],
                ['id' => '15', 'messagesAdded' => [['message' => ['id' => 'new1']]]],
            ],
            'historyId' => '20',
            'nextPageToken' => 'more',
        ])])->listChanges($this->connectedAccount(), '10', 'page-1');

        $this->assertSame(['new1', 'lab1', 'lab2'], $page->changedIds);
        $this->assertSame(['tmp', 'old'], $page->deletedIds);
        $this->assertSame('20', $page->historyId);
        $this->assertSame('more', $page->nextPageToken);

        $query = $this->queryOf((string) $this->history[0]['request']->getUri());
        $this->assertSame('10', $query['startHistoryId']);
        $this->assertSame('page-1', $query['pageToken']);
    }

    public function test_history_404_throws_history_expired(): void
    {
        $this->expectException(HistoryExpired::class);

        $this->service([$this->jsonResponse(['error' => ['code' => 404, 'message' => 'Requested entity was not found.']], 404)])
            ->listChanges($this->connectedAccount(), '1');
    }

    public function test_rate_limit_throws_provider_unavailable(): void
    {
        try {
            $this->service([$this->jsonResponse(['error' => ['code' => 429, 'message' => 'Rate Limit Exceeded']], 429)])
                ->listMessageIds($this->connectedAccount(), 'after:1');
            $this->fail('Expected ProviderUnavailable');
        } catch (ProviderUnavailable $e) {
            $this->assertSame('rate_limited', $e->getMessage());
        }
    }

    public function test_network_failure_throws_provider_unavailable(): void
    {
        $this->expectException(ProviderUnavailable::class);
        $this->expectExceptionMessage('request_failed');

        $this->service([new ConnectException('down', new Request('GET', 'x'))])
            ->currentHistoryId($this->connectedAccount());
    }

    public function test_unauthorized_marks_account_for_reauth(): void
    {
        $account = $this->connectedAccount();

        try {
            $this->service([$this->jsonResponse(['error' => ['code' => 401, 'message' => 'Invalid Credentials']], 401)])
                ->listChanges($account, '1');
            $this->fail('Expected ReauthorizationRequired');
        } catch (ReauthorizationRequired) {
            $this->assertNotNull($account->fresh()->reauth_required_at);
        }
    }

    public function test_refreshes_expired_token_before_listing(): void
    {
        $account = EmailAccount::factory()->create(['token_expires_at' => now()->subMinute()]);

        $this->service([
            $this->tokenResponse(['access_token' => 'refreshed-access']),
            $this->jsonResponse(['messages' => []]),
        ])->listMessageIds($account, 'after:1');

        $this->assertSame('Bearer refreshed-access', $this->history[1]['request']->getHeaderLine('Authorization'));
    }
}
