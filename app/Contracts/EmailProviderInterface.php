<?php

namespace App\Contracts;

use App\Exceptions\HistoryExpired;
use App\Exceptions\MissingRequiredScopes;
use App\Exceptions\OAuthExchangeFailed;
use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use App\Models\EmailAccount;
use App\Services\Providers\ChangePage;
use App\Services\Providers\MessageMetadata;
use App\Services\Providers\MessagePage;
use App\Services\Providers\OAuthGrant;

interface EmailProviderInterface
{
    public function authorizationUrl(string $state, ?string $loginHint = null): string;

    /**
     * @throws MissingRequiredScopes when the user unticked a required permission
     * @throws OAuthExchangeFailed
     */
    public function exchangeCode(string $code): OAuthGrant;

    /**
     * @throws ReauthorizationRequired
     * @throws OAuthExchangeFailed
     */
    public function refreshAccessToken(EmailAccount $account): string;

    public function revoke(EmailAccount $account): bool;

    /*
     * The methods below refresh the access token first and throw
     * ReauthorizationRequired or ProviderUnavailable on failure.
     */

    /**
     * @throws ReauthorizationRequired
     * @throws ProviderUnavailable
     */
    public function currentHistoryId(EmailAccount $account): string;

    /**
     * Newest first; spam and trash are excluded.
     *
     * @throws ReauthorizationRequired
     * @throws ProviderUnavailable
     */
    public function listMessageIds(EmailAccount $account, string $query, ?string $pageToken = null, int $maxResults = 100): MessagePage;

    /**
     * Messages that no longer exist are omitted.
     *
     * @param  list<string>  $ids
     * @return list<MessageMetadata>
     *
     * @throws ReauthorizationRequired
     * @throws ProviderUnavailable
     */
    public function fetchMetadata(EmailAccount $account, array $ids): array;

    /**
     * @throws HistoryExpired when the start point is too old for the provider
     * @throws ReauthorizationRequired
     * @throws ProviderUnavailable
     */
    public function listChanges(EmailAccount $account, string $startHistoryId, ?string $pageToken = null): ChangePage;
}
