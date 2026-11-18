<?php

namespace App\Console\Commands;

use App\Jobs\SyncEmailAccountJob;
use App\Models\EmailAccount;
use App\Services\Sync\SyncMode;
use Illuminate\Console\Command;

class ImportHistoryCommand extends Command
{
    protected $signature = 'inbox:import-history';

    protected $description = 'Queue the next slice of the older-mail import for accounts whose history is incomplete';

    public function handle(): int
    {
        $accounts = EmailAccount::syncable()
            ->whereNotNull('history_id')
            ->whereNull('history_completed_at')
            ->get()
            ->reject->needsReauth();

        $accounts->each(fn (EmailAccount $account) => SyncEmailAccountJob::dispatch($account->id, SyncMode::History));

        $this->info("Importação do histórico enfileirada para {$accounts->count()} conta(s).");

        return self::SUCCESS;
    }
}
