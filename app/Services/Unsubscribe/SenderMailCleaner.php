<?php

namespace App\Services\Unsubscribe;

use App\Contracts\EmailProviderInterface;
use App\Models\EmailSender;
use App\Services\Providers\GmailSearch;
use App\Services\Sync\MessageStore;

/**
 * Archives or trashes every message from a sender at the provider (including
 * mail not imported yet, never starred mail) and mirrors the change locally.
 */
class SenderMailCleaner
{
    public const KEEP = 'keep';

    public const ARCHIVE = 'archive';

    public const TRASH = 'trash';

    public function __construct(
        private readonly EmailProviderInterface $provider,
        private readonly MessageStore $store,
    ) {}

    public function clean(EmailSender $sender, string $cleanup): void
    {
        if ($cleanup === self::KEEP) {
            return;
        }

        [$add, $remove] = $cleanup === self::TRASH ? [['TRASH'], []] : [[], ['INBOX']];
        $account = $sender->account;

        $ids = $this->provider->listAllMessageIds($account, GmailSearch::PROTECTED.' '.GmailSearch::from($sender->email));
        $this->provider->batchModify($account, $ids, $add, $remove);
        $this->store->applyLabelChange($account, $ids, $add, $remove);
    }
}
