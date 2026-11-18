<?php

namespace Tests\Fakes;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\OAuthExchangeFailed;
use App\Models\EmailAccount;
use App\Services\Providers\ChangePage;
use App\Services\Providers\GmailProviderService;
use App\Services\Providers\MessageMetadata;
use App\Services\Providers\MessagePage;
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

    /** @var array<string, MessageMetadata> in-memory mailbox, keyed by id */
    public array $mailbox = [];

    public string $historyId = '1000';

    /** @var list<ChangePage|Throwable> returned by listChanges, one per call */
    public array $changePages = [];

    /** @var list<array{query: string, pageToken: ?string}> */
    public array $listCalls = [];

    /** @var array<string, Throwable> thrown by the named method on its next call */
    public array $failNext = [];

    /** @var (callable(string): void)|null called before every sync method */
    public $beforeCall = null;

    public static function message(string $id, string $from, CarbonImmutable $date, array $overrides = []): MessageMetadata
    {
        return new MessageMetadata(...array_merge([
            'id' => $id,
            'threadId' => 'thread-'.$id,
            'labelIds' => ['INBOX', 'UNREAD'],
            'internalDate' => $date,
            'from' => $from,
            'subject' => 'Assunto '.$id,
            'listUnsubscribe' => null,
            'listUnsubscribePost' => null,
        ], $overrides));
    }

    public function addMessages(MessageMetadata ...$messages): void
    {
        foreach ($messages as $message) {
            $this->mailbox[$message->id] = $message;
        }
    }

    public function currentHistoryId(EmailAccount $account): string
    {
        $this->hit('currentHistoryId');

        return $this->historyId;
    }

    public function listMessageIds(EmailAccount $account, string $query, ?string $pageToken = null, int $maxResults = 100): MessagePage
    {
        $this->hit('listMessageIds');
        $this->listCalls[] = ['query' => $query, 'pageToken' => $pageToken];

        preg_match('/after:(\d+)/', $query, $after);
        preg_match('/before:(\d+)/', $query, $before);

        $matching = array_filter($this->mailbox, fn (MessageMetadata $m) => (! $after || $m->internalDate->timestamp >= (int) $after[1])
            && (! $before || $m->internalDate->timestamp < (int) $before[1]));
        usort($matching, fn (MessageMetadata $a, MessageMetadata $b) => $b->internalDate <=> $a->internalDate ?: strcmp($b->id, $a->id));

        $offset = (int) ($pageToken ?? 0);
        $page = array_slice($matching, $offset, $maxResults);
        $next = $offset + $maxResults < count($matching) ? (string) ($offset + $maxResults) : null;

        return new MessagePage(array_map(fn (MessageMetadata $m) => $m->id, $page), $next);
    }

    public function fetchMetadata(EmailAccount $account, array $ids): array
    {
        $this->hit('fetchMetadata');

        return array_values(array_filter(array_map(fn (string $id) => $this->mailbox[$id] ?? null, $ids)));
    }

    public function listChanges(EmailAccount $account, string $startHistoryId, ?string $pageToken = null): ChangePage
    {
        $this->hit('listChanges');
        $page = array_shift($this->changePages) ?? new ChangePage([], [], $this->historyId, null);

        if ($page instanceof Throwable) {
            throw $page;
        }

        return $page;
    }

    private function hit(string $method): void
    {
        if ($this->beforeCall !== null) {
            ($this->beforeCall)($method);
        }

        if (isset($this->failNext[$method])) {
            $exception = $this->failNext[$method];
            unset($this->failNext[$method]);

            throw $exception;
        }
    }

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
