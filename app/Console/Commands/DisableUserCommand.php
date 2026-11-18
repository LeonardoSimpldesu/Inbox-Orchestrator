<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsUserByEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DisableUserCommand extends Command
{
    use FindsUserByEmail;

    protected $signature = 'user:disable {email : E-mail do usuário} {--force : Não pedir confirmação}';

    protected $description = 'Desativa um usuário e encerra as sessões dele imediatamente';

    public function handle(): int
    {
        $user = $this->findUserOrReport();

        if ($user === null) {
            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Desativar {$user->email}? A pessoa será desconectada imediatamente.")) {
            $this->line('Nada foi alterado.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user) {
            $user->forceFill([
                'disabled_at' => now(),
                // Invalidates every existing "remember me" cookie.
                'remember_token' => Str::random(60),
            ])->save();

            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        });

        $this->info("Usuário {$user->email} desativado.");

        return self::SUCCESS;
    }
}
