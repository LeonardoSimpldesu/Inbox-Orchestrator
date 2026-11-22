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
    /**
     * @param  list<string>  $labelIds  messages must carry all of them
     */
    public function listMessageIds(EmailAccount $account, string $query, ?string $pageToken = null, int $maxResults = 100, array $labelIds = []): MessagePage;

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

    /**
     * Sends a plain-text e-mail from the account (used for mailto unsubscribe).
     *
     * @throws ReauthorizationRequired
     * @throws ProviderUnavailable
     */
    public function sendMessage(EmailAccount $account, string $to, string $subject, string $body): void;

    /**
     * Every message id matching the query (all pages, or up to $limit).
     *
     * @param  list<string>  $labelIds
     * @return list<string>
     *
     * @throws ReauthorizationRequired
     * @throws ProviderUnavailable
     */
    public function listAllMessageIds(EmailAccount $account, string $query, array $labelIds = [], ?int $limit = null): array;

    /**
     * The user's own labels, sorted by name.
     *
     * @return list<array{id: string, name: string}>
     *
     * @throws ReauthorizationRequired
     * @throws ProviderUnavailable
     */
    public function listLabels(EmailAccount $account): array;

    /**
     * Creates a label, or returns the existing one with the same name.
     *
     * @return array{id: string, name: string}
     *
     * @throws ReauthorizationRequired
     * @throws ProviderUnavailable
     */
    public function createLabel(EmailAccount $account, string $name): array;

    /**
     * @param  list<string>  $ids
     * @param  list<string>  $addLabels
     * @param  list<string>  $removeLabels
     *
     * @throws ReauthorizationRequired
     * @throws ProviderUnavailable
     */
    public function batchModify(EmailAccount $account, array $ids, array $addLabels, array $removeLabels): void;
}
