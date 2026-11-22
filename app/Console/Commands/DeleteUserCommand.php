<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsUserByEmail;
use App\Contracts\EmailProviderInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeleteUserCommand extends Command
{
    use FindsUserByEmail;

    protected $signature = 'user:delete {email : E-mail do usuário} {--force : Não pedir confirmação}';

    protected $description = 'Remove um usuário e todos os dados dele (contas de e-mail, remetentes, regras)';

    public function handle(EmailProviderInterface $provider): int
    {
        $user = $this->findUserOrReport();

        if ($user === null) {
            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn("Isto apaga {$user->email} e todos os dados dele. Não há como desfazer.");
            $typed = Str::lower(trim((string) $this->ask('Para confirmar, digite o e-mail do usuário')));

            if ($typed !== $user->email) {
                $this->error('Confirmação incorreta. Nada foi apagado.');

                return self::FAILURE;
            }
        }

        foreach ($user->emailAccounts as $account) {
            if (! $provider->revoke($account)) {
                Log::warning('Gmail token revocation failed on user deletion.', ['account_id' => $account->id]);
            }
        }

        DB::transaction(function () use ($user) {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            // E-mail accounts, senders, message metadata and rules cascade via foreign keys.
            $user->delete();
        });

        $this->info("Usuário {$user->email} removido.");

        return self::SUCCESS;
    }
}
