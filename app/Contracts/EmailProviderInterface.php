<?php

namespace App\Contracts;

use App\Exceptions\OAuthExchangeFailed;
use App\Exceptions\ReauthorizationRequired;
use App\Models\EmailAccount;
use App\Services\Providers\OAuthGrant;

interface EmailProviderInterface
{
    public function authorizationUrl(string $state, ?string $loginHint = null): string;

    /**
     * @throws OAuthExchangeFailed
     */
    public function exchangeCode(string $code): OAuthGrant;

    /**
     * @throws ReauthorizationRequired
     * @throws OAuthExchangeFailed
     */
    public function refreshAccessToken(EmailAccount $account): string;

    public function revoke(EmailAccount $account): bool;
}
