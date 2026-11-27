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

    /** @var list<array{account_id: int, to: string, subject: string, body: string}> */
    public array $sent = [];

    /** @var list<array{id: string, name: string}> */
    public array $labels = [];

    /** @var list<string>|null when set, listAllMessageIds returns these ids (cheap large listings) */
    public ?array $listAllOverride = null;

    /** @var list<array{account_id: int, ids: list<string>, add: list<string>, remove: list<string>}> */
    public array $modified = [];

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

    public function listMessageIds(EmailAccount $account, string $query, ?string $pageToken = null, int $maxResults = 100, array $labelIds = []): MessagePage
    {
        $this->hit('listMessageIds');
        $this->listCalls[] = ['query' => $query, 'pageToken' => $pageToken];

        $matching = $this->matching($query, $labelIds);
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

    public function sendMessage(EmailAccount $account, string $to, string $subject, string $body): void
    {
        $this->hit('sendMessage');
        $this->sent[] = ['account_id' => $account->id, 'to' => $to, 'subject' => $subject, 'body' => $body];
    }

    public function listAllMessageIds(EmailAccount $account, string $query, array $labelIds = [], ?int $limit = null): array
    {
        $this->hit('listAllMessageIds');
        $this->listCalls[] = ['query' => $query, 'pageToken' => null, 'labelIds' => $labelIds, 'limit' => $limit];

        if ($this->listAllOverride !== null) {
            return $limit === null ? $this->listAllOverride : array_slice($this->listAllOverride, 0, $limit);
        }

        $matching = $this->matching($query, $labelIds);
        usort($matching, fn (MessageMetadata $a, MessageMetadata $b) => $b->internalDate <=> $a->internalDate ?: strcmp($b->id, $a->id));
        $ids = array_map(fn (MessageMetadata $m) => $m->id, $matching);

        return $limit === null ? $ids : array_slice($ids, 0, $limit);
    }

    public function listLabels(EmailAccount $account): array
    {
        $this->hit('listLabels');

        return $this->labels;
    }

    public function createLabel(EmailAccount $account, string $name): array
    {
        $this->hit('createLabel');
        $label = ['id' => 'Label_'.(count($this->labels) + 1), 'name' => trim($name)];
        $this->labels[] = $label;

        return $label;
    }

    public function batchModify(EmailAccount $account, array $ids, array $addLabels, array $removeLabels): void
    {
        $this->hit('batchModify');

        if ($ids !== []) {
            $this->modified[] = ['account_id' => $account->id, 'ids' => $ids, 'add' => $addLabels, 'remove' => $removeLabels];
        }
    }

    /**
     * Supports the after:/before: epoch filters and from:address used by the app.
     *
     * @return array<string, MessageMetadata>
     */
    private function matching(string $query, array $labelIds = []): array
    {
        preg_match('/after:(\\d+)/', $query, $after);
        preg_match('/before:(\\d+)/', $query, $before);
        preg_match('/from:"?([^"\\s]+)"?/', $query, $from);

        preg_match('/older_than:(\\d+)d/', $query, $olderThan);
        $notInInbox = str_contains($query, '-in:inbox');
        $inInbox = ! $notInInbox && str_contains($query, 'in:inbox');

        return array_filter($this->mailbox, fn (MessageMetadata $m) => array_diff($labelIds, $m->labelIds) === []
            && (! $olderThan || $m->internalDate->lessThan(now()->subDays((int) $olderThan[1])))
            && (! $inInbox || in_array('INBOX', $m->labelIds, true))
            && (! $notInInbox || ! in_array('INBOX', $m->labelIds, true))
            && (! str_contains($query, '-is:starred') || ! in_array('STARRED', $m->labelIds, true))
            && (! $after || $m->internalDate->timestamp >= (int) $after[1])
            && (! $before || $m->internalDate->timestamp < (int) $before[1])
            && (! $from || str_contains(strtolower((string) $m->from), strtolower($from[1]))));
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
