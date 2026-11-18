<?php

namespace App\Console\Commands;

use App\Jobs\SyncEmailAccountJob;
use App\Models\EmailAccount;
use App\Services\Sync\SyncMode;
use Illuminate\Console\Command;

class SyncAccountsCommand extends Command
{
    protected $signature = 'inbox:sync';

    protected $description = 'Queue a sync of recent changes for every connected Gmail account';

    public function handle(): int
    {
        $accounts = EmailAccount::syncable()->get()->reject->needsReauth();

        $accounts->each(fn (EmailAccount $account) => SyncEmailAccountJob::dispatch($account->id, SyncMode::Changes));

        $this->info("Sincronização enfileirada para {$accounts->count()} conta(s).");

        return self::SUCCESS;
    }
}
