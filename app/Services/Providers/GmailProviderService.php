<?php

namespace App\Services\Providers;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\MissingRequiredScopes;
use App\Exceptions\OAuthExchangeFailed;
use App\Exceptions\ReauthorizationRequired;
use App\Models\EmailAccount;
use Carbon\CarbonImmutable;
use Google\Client;
use Google\Service\Gmail;
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
