<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsUserByEmail;
use Illuminate\Console\Command;

class EnableUserCommand extends Command
{
    use FindsUserByEmail;

    protected $signature = 'user:enable {email : E-mail do usuário}';

    protected $description = 'Reativa um usuário desativado';

    public function handle(): int
    {
        $user = $this->findUserOrReport();

        if ($user === null) {
            return self::FAILURE;
        }

        $user->forceFill(['disabled_at' => null])->save();

        $this->info("Usuário {$user->email} reativado.");

        return self::SUCCESS;
    }
}
