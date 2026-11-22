<?php

namespace App\Services;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\HistoryExpired;
use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use App\Models\EmailAccount;
use App\Services\Sync\MessageStore;
use App\Services\Sync\SyncMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class EmailSyncService
{
    private const RECENT_WINDOW_DAYS = 90;

    private const PAGE_SIZE = 100;

    private SyncMode $mode;

    private CarbonImmutable $startedAt;

    private int $imported = 0;

    public function __construct(
        private readonly EmailProviderInterface $provider,
        private readonly MessageStore $store,
    ) {}

    public function run(EmailAccount $account, SyncMode $mode): void
    {
        $this->mode = $mode;
        $this->startedAt = CarbonImmutable::now();
        $this->imported = 0;

        try {
            $finished = match ($mode) {
                SyncMode::Recent => $this->importRecent($account),
                SyncMode::Changes => $this->applyChanges($account),
                SyncMode::History, SyncMode::HistoryNow => $this->importHistory($account),
            };
        } catch (ReauthorizationRequired) {
            return;
        } catch (ProviderUnavailable $e) {
            Log::warning('Gmail sync stopped: provider unavailable.', [
                'account_id' => $account->id,
                'mode' => $mode->value,
                'error' => $e->getMessage(),
            ]);

            return;
        } finally {
            if ($mode === SyncMode::HistoryNow) {
                $account->forceFill(['history_import_requested_at' => null])->save();
            }
        }

        if ($finished) {
            $account->forceFill(['last_synced_at' => now()])->save();
        }
    }

    /**
     * The history cursor is read before listing so that mail arriving during
     * the import is picked up by the next Changes run.
     */
    private function importRecent(EmailAccount $account): bool
    {
        $historyId = $this->provider->currentHistoryId($account);
        $windowStart = CarbonImmutable::now()->subDays(self::RECENT_WINDOW_DAYS);

        if ($this->importPages($account, 'after:'.$windowStart->timestamp) === null) {
            return false;
        }

        $account->forceFill([
            'history_id' => $historyId,
            'oldest_synced_at' => $account->oldest_synced_at ?? $windowStart,
        ])->save();

        return true;
    }

    private function importHistory(EmailAccount $account): bool
    {
        if ($account->oldest_synced_at === null) {
            return false;
        }

        // +1 s re-reads messages sharing the oldest second; upserts make that harmless.
        $exhausted = $this->importPages($account, 'before:'.($account->oldest_synced_at->timestamp + 1));

        if ($exhausted === null) {
            return false;
        }

        if ($exhausted) {
            $account->forceFill(['history_completed_at' => now()])->save();
        }

        return true;
    }

    private function applyChanges(EmailAccount $account): bool
    {
        if ($account->history_id === null) {
            return $this->importRecent($account);
        }

        $pageToken = null;

        try {
            do {
                $page = $this->provider->listChanges($account, $account->history_id, $pageToken);

                foreach (array_chunk($page->changedIds, self::PAGE_SIZE) as $ids) {
                    $this->store->store($account, $this->provider->fetchMetadata($account, $ids), extendsCoverage: false);
                }

                $this->store->delete($account, $page->deletedIds);
                $pageToken = $page->nextPageToken;

                // Every page reports the mailbox's latest history id, so it is only
                // saved once all pages are applied; a partial run is simply redone.
                if ($pageToken !== null && ! $this->canContinue($account)) {
                    return false;
                }
            } while ($pageToken !== null);
        } catch (HistoryExpired) {
            // Changes older than the recent window were missed while the account was idle:
            // walk the whole mailbox again (writes are idempotent).
            $account->forceFill(['oldest_synced_at' => null, 'history_completed_at' => null])->save();

            return $this->importRecent($account);
        }

        $account->forceFill(['history_id' => $page->historyId])->save();

        return true;
    }

    /**
     * @return bool|null true when the listing was exhausted, false when the budget
     *                   ran out, null when the run must stop (account no longer syncable)
     */
    private function importPages(EmailAccount $account, string $query): ?bool
    {
        $pageToken = null;

        do {
            $limit = $this->mode->messageLimit();
            $size = $limit === null ? self::PAGE_SIZE : min(self::PAGE_SIZE, $limit - $this->imported);
            $page = $this->provider->listMessageIds($account, $query, $pageToken, $size);

            if ($page->ids !== []) {
                $this->store->store($account, $this->provider->fetchMetadata($account, $page->ids));
                $this->imported += count($page->ids);
            }

            $pageToken = $page->nextPageToken;

            if (! $this->accountStillSyncable($account)) {
                return null;
            }

            if ($pageToken !== null && ! $this->withinBudget()) {
                return false;
            }
        } while ($pageToken !== null);

        return true;
    }

    private function canContinue(EmailAccount $account): bool
    {
        return $this->accountStillSyncable($account) && $this->withinBudget();
    }

    private function withinBudget(): bool
    {
        $limit = $this->mode->messageLimit();

        return ($limit === null || $this->imported < $limit)
            && $this->startedAt->diffInSeconds(CarbonImmutable::now()) < $this->mode->timeLimitSeconds();
    }

    private function accountStillSyncable(EmailAccount $account): bool
    {
        return EmailAccount::whereKey($account->id)->whereNull('reauth_required_at')->exists();
    }
}
