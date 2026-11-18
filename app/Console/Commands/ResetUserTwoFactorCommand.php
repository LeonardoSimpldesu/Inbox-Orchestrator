<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsUserByEmail;
use Illuminate\Console\Command;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

class ResetUserTwoFactorCommand extends Command
{
    use FindsUserByEmail;

    protected $signature = 'user:reset-two-factor {email : E-mail do usuário} {--force : Não pedir confirmação}';

    protected $description = 'Desativa o 2FA de quem perdeu o app autenticador e os códigos de recuperação';

    public function handle(DisableTwoFactorAuthentication $disable): int
    {
        $user = $this->findUserOrReport();

        if ($user === null) {
            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Desativar a verificação em duas etapas de {$user->email}?")) {
            $this->line('Nada foi alterado.');

            return self::FAILURE;
        }

        $disable($user);

        $this->info("Verificação em duas etapas de {$user->email} desativada.");

        return self::SUCCESS;
    }
}
