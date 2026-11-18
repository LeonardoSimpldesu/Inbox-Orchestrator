<?php

namespace Tests\Fakes;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\OAuthExchangeFailed;
use App\Models\EmailAccount;
use App\Services\Providers\GmailProviderService;
use App\Services\Providers\OAuthGrant;
use Carbon\CarbonImmutable;
use Throwable;

class FakeEmailProvider implements EmailProviderInterface
{
    public ?OAuthGrant $grant = null;

    public ?Throwable $exchangeException = null;

    public bool $revokeResult = true;

    /** @var list<int> */
    public array $revoked = [];

    /** @var list<string> */
    public array $exchangedCodes = [];

    /** @var array{state: string, loginHint: ?string}|null */
    public ?array $lastAuthorization = null;

    public static function grantFor(string $email, array $overrides = []): OAuthGrant
    {
        return new OAuthGrant(...array_merge([
            'accessToken' => 'access-'.$email,
            'refreshToken' => 'refresh-'.$email,
            'expiresAt' => CarbonImmutable::now()->addHour(),
            'grantedScopes' => GmailProviderService::SCOPES,
            'email' => $email,
        ], $overrides));
    }

    public function authorizationUrl(string $state, ?string $loginHint = null): string
    {
        $this->lastAuthorization = ['state' => $state, 'loginHint' => $loginHint];

        return 'https://accounts.google.test/auth?state='.$state;
    }

    public function exchangeCode(string $code): OAuthGrant
    {
        $this->exchangedCodes[] = $code;

        if ($this->exchangeException !== null) {
            throw $this->exchangeException;
        }

        return $this->grant ?? throw new OAuthExchangeFailed('no_grant_configured');
    }

    public function refreshAccessToken(EmailAccount $account): string
    {
        return $account->access_token;
    }

    public function revoke(EmailAccount $account): bool
    {
        $this->revoked[] = $account->id;

        return $this->revokeResult;
    }
}
