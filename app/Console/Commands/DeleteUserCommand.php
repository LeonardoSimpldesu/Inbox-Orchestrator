<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsUserByEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeleteUserCommand extends Command
{
    use FindsUserByEmail;

    protected $signature = 'user:delete {email : E-mail do usuário} {--force : Não pedir confirmação}';

    protected $description = 'Remove um usuário e todos os dados dele (contas de e-mail, remetentes, regras)';

    public function handle(): int
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

        DB::transaction(function () use ($user) {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            // E-mail accounts, senders, message metadata and rules cascade via foreign keys.
            $user->delete();
        });

        $this->info("Usuário {$user->email} removido.");

        return self::SUCCESS;
    }
}
