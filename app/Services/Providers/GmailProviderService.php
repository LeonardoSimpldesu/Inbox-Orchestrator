<?php

namespace App\Services\Providers;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\HistoryExpired;
use App\Exceptions\MissingRequiredScopes;
use App\Exceptions\OAuthExchangeFailed;
use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use App\Models\EmailAccount;
use Carbon\CarbonImmutable;
use Google\Client;
use Google\Http\Batch;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Str;
use Throwable;

class GmailProviderService implements EmailProviderInterface
{
    public const SCOPES = [
        Gmail::GMAIL_MODIFY,
        Gmail::GMAIL_SEND,
    ];

    private const REFRESH_LEEWAY_SECONDS = 60;

    private const BATCH_SIZE = 100;

    private const METADATA_HEADERS = ['From', 'Subject', 'List-Unsubscribe', 'List-Unsubscribe-Post'];

    public function __construct(private readonly ?ClientInterface $http = null) {}

    public function authorizationUrl(string $state, ?string $loginHint = null): string
    {
        $client = $this->client();
        $client->setState($state);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(false);

        if ($loginHint !== null) {
            $client->setLoginHint($loginHint);
        }

        return $client->createAuthUrl(self::SCOPES);
    }

    public function exchangeCode(string $code): OAuthGrant
    {
        $client = $this->client();
        $token = $this->request(fn () => $client->fetchAccessTokenWithAuthCode($code));
        $grantedScopes = explode(' ', (string) ($token['scope'] ?? ''));

        // Checked before reading the profile: without gmail.modify that call fails too.
        if (array_diff(self::SCOPES, $grantedScopes) !== []) {
            throw new MissingRequiredScopes('missing_scopes');
        }

        try {
            $email = (new Gmail($client))->users->getProfile('me')->getEmailAddress();
        } catch (Throwable) {
            throw new OAuthExchangeFailed('profile_unavailable');
        }

        return new OAuthGrant(
            accessToken: $token['access_token'],
            refreshToken: $token['refresh_token'] ?? null,
            expiresAt: CarbonImmutable::now()->addSeconds((int) $token['expires_in']),
            grantedScopes: $grantedScopes,
            email: Str::lower($email),
        );
    }

    public function refreshAccessToken(EmailAccount $account): string
    {
        if ($account->token_expires_at?->isAfter(now()->addSeconds(self::REFRESH_LEEWAY_SECONDS))) {
            return $account->access_token;
        }

        $client = $this->client();

        try {
            $token = $this->request(fn () => $client->fetchAccessTokenWithRefreshToken($account->refresh_token));
        } catch (OAuthExchangeFailed $e) {
            if ($e->getMessage() === 'invalid_grant') {
                $account->forceFill(['reauth_required_at' => now()])->save();

                throw new ReauthorizationRequired('invalid_grant', previous: $e);
            }

            throw $e;
        }

        $account->forceFill([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds((int) $token['expires_in']),
        ])->save();

        return $account->access_token;
    }

    public function revoke(EmailAccount $account): bool
    {
        try {
            return $this->client()->revokeToken($account->refresh_token);
        } catch (Throwable) {
            return false;
        }
    }

    public function currentHistoryId(EmailAccount $account): string
    {
        $gmail = $this->gmail($account);

        return (string) $this->call($account, fn () => $gmail->users->getProfile('me')->getHistoryId());
    }

    public function listMessageIds(EmailAccount $account, string $query, ?string $pageToken = null, int $maxResults = 100): MessagePage
    {
        $gmail = $this->gmail($account);
        $params = array_filter(['q' => $query, 'pageToken' => $pageToken, 'maxResults' => $maxResults]);

        $response = $this->call($account, fn () => $gmail->users_messages->listUsersMessages('me', $params));

        return new MessagePage(
            ids: array_map(fn (Message $message) => $message->getId(), $response->getMessages() ?? []),
            nextPageToken: $response->getNextPageToken(),
        );
    }

    public function fetchMetadata(EmailAccount $account, array $ids): array
    {
        $messages = [];

        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            $gmail = $this->gmail($account);
            $gmail->getClient()->setUseBatch(true);
            $batch = new Batch($gmail->getClient(), false, $gmail->rootUrl, $gmail->batchPath);

            foreach ($chunk as $id) {
                $batch->add($gmail->users_messages->get('me', $id, [
                    'format' => 'metadata',
                    'metadataHeaders' => self::METADATA_HEADERS,
                ]), $id);
            }

            $responses = $this->call($account, fn () => $batch->execute()) ?? [];

            foreach ($chunk as $id) {
                $response = $responses['response-'.$id] ?? null;

                if ($response instanceof GoogleServiceException && $response->getCode() !== 404) {
                    throw new ProviderUnavailable('http_'.$response->getCode());
                }

                if ($response instanceof Message) {
                    $messages[] = $this->toMetadata($response);
                }
            }
        }

        return $messages;
    }

    public function listChanges(EmailAccount $account, string $startHistoryId, ?string $pageToken = null): ChangePage
    {
        $gmail = $this->gmail($account);
        $params = array_filter([
            'startHistoryId' => $startHistoryId,
            'pageToken' => $pageToken,
            'historyTypes' => ['messageAdded', 'messageDeleted', 'labelAdded', 'labelRemoved'],
        ]);

        try {
            $response = $this->call($account, fn () => $gmail->users_history->listUsersHistory('me', $params), rethrow404: true);
        } catch (GoogleServiceException) {
            throw new HistoryExpired('history_expired');
        }

        $changed = [];
        $deleted = [];

        foreach ($response->getHistory() ?? [] as $record) {
            foreach ([...($record->getMessagesAdded() ?? []), ...($record->getLabelsAdded() ?? []), ...($record->getLabelsRemoved() ?? [])] as $change) {
                $changed[$change->getMessage()->getId()] = true;
            }

            foreach ($record->getMessagesDeleted() ?? [] as $change) {
                $deleted[$change->getMessage()->getId()] = true;
            }
        }

        return new ChangePage(
            changedIds: array_keys(array_diff_key($changed, $deleted)),
            deletedIds: array_map('strval', array_keys($deleted)),
            historyId: (string) $response->getHistoryId(),
            nextPageToken: $response->getNextPageToken(),
        );
    }

    private function gmail(EmailAccount $account): Gmail
    {
        $client = $this->client();
        $client->setAccessToken([
            'access_token' => $this->refreshAccessToken($account),
            'expires_in' => 3600,
            'created' => time(),
        ]);

        return new Gmail($client);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function call(EmailAccount $account, callable $call, bool $rethrow404 = false): mixed
    {
        try {
            return $call();
        } catch (GoogleServiceException $e) {
            if ($rethrow404 && $e->getCode() === 404) {
                throw $e;
            }

            if ($e->getCode() === 401) {
                $account->forceFill(['reauth_required_at' => now()])->save();

                throw new ReauthorizationRequired('unauthorized', previous: $e);
            }

            throw new ProviderUnavailable('http_'.$e->getCode(), previous: $e);
        } catch (Throwable $e) {
            throw new ProviderUnavailable('request_failed', previous: $e);
        }
    }

    private function toMetadata(Message $message): MessageMetadata
    {
        $headers = [];

        foreach ($message->getPayload()?->getHeaders() ?? [] as $header) {
            $headers[Str::lower($header->getName())] ??= $header->getValue();
        }

        return new MessageMetadata(
            id: $message->getId(),
            threadId: (string) $message->getThreadId(),
            labelIds: array_values($message->getLabelIds() ?? []),
            internalDate: CarbonImmutable::createFromTimestampMs((int) $message->getInternalDate()),
            from: $headers['from'] ?? null,
            subject: $headers['subject'] ?? null,
            listUnsubscribe: $headers['list-unsubscribe'] ?? null,
            listUnsubscribePost: $headers['list-unsubscribe-post'] ?? null,
        );
    }

    private function client(): Client
    {
        $client = new Client;
        $client->setClientId((string) config('services.google.client_id'));
        $client->setClientSecret((string) config('services.google.client_secret'));
        $client->setRedirectUri((string) config('services.google.redirect'));

        if ($this->http !== null) {
            $client->setHttpClient($this->http);
        }

        return $client;
    }

    /**
     * Google's client reports OAuth errors as an ['error' => ...] array or throws,
     * depending on the HTTP client; both become an exception carrying only the error type.
     *
     * @param  callable(): array<string, mixed>  $call
     * @return array<string, mixed>
     */
    private function request(callable $call): array
    {
        try {
            $token = $call();
        } catch (Throwable) {
            throw new OAuthExchangeFailed('request_failed');
        }

        if (isset($token['error']) || ! isset($token['access_token'])) {
            throw new OAuthExchangeFailed(is_string($token['error'] ?? null) ? $token['error'] : 'invalid_response');
        }

        return $token;
    }
}
