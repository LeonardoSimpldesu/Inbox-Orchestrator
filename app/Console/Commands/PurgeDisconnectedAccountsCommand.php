<?php

namespace App\Console\Commands;

use App\Models\EmailAccount;
use Illuminate\Console\Command;

class PurgeDisconnectedAccountsCommand extends Command
{
    protected $signature = 'inbox:purge-disconnected-accounts';

    protected $description = 'Delete Gmail accounts disconnected longer than the retention period, with their synced data';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('app.disconnected_account_retention_days'));

        // Senders, message metadata and rules go with the account through the foreign keys' cascade.
        $deleted = EmailAccount::onlyTrashed()->where('deleted_at', '<', $cutoff)->forceDelete();

        $this->info("{$deleted} conta(s) desconectada(s) apagada(s).");

        return self::SUCCESS;
    }
}
