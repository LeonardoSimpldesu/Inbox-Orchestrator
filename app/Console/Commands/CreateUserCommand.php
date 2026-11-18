<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateUserCommand extends Command
{
    protected $signature = 'user:create
        {name : Nome da pessoa}
        {email : E-mail usado no login}
        {--password= : Senha (se omitida, é pedida no terminal ou gerada no modo não interativo)}';

    protected $description = 'Cria um usuário (o cadastro público está desativado)';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $email = Str::lower(trim((string) $this->argument('email')));

        $identity = Validator::make(compact('name', 'email'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ]);

        if ($identity->fails()) {
            return $this->reject($identity->errors()->all());
        }

        [$password, $generated] = $this->resolvePassword();

        if ($password === null) {
            return $this->reject(['As senhas não conferem.']);
        }

        $policy = Validator::make(compact('password'), ['password' => ['required', 'string', Password::defaults()]]);

        if ($policy->fails()) {
            return $this->reject($policy->errors()->all());
        }

        User::create(compact('name', 'email', 'password'));

        $this->info("Usuário {$email} criado.");

        if ($generated) {
            $this->warn('Anote a senha abaixo: ela não será exibida novamente.');
            $this->line("Senha gerada: {$password}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: string|null, 1: bool} the password (null when the confirmation differs) and whether it was generated
     */
    private function resolvePassword(): array
    {
        $option = $this->option('password');

        if (is_string($option) && $option !== '') {
            return [$option, false];
        }

        if (! $this->input->isInteractive()) {
            return [Str::password(24, symbols: false), true];
        }

        $password = (string) $this->secret('Senha');
        $confirmation = (string) $this->secret('Confirme a senha');

        return [hash_equals($password, $confirmation) ? $password : null, false];
    }

    /**
     * @param  list<string>  $messages
     */
    private function reject(array $messages): int
    {
        foreach ($messages as $message) {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
