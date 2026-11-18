<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;
use Illuminate\Support\Str;

trait FindsUserByEmail
{
    protected function normalizedEmail(): string
    {
        return Str::lower(trim((string) $this->argument('email')));
    }

    /**
     * Resolves the `email` argument, printing an error when no user matches.
     */
    protected function findUserOrReport(): ?User
    {
        $user = User::where('email', $this->normalizedEmail())->first();

        if ($user === null) {
            $this->error('Usuário não encontrado.');
        }

        return $user;
    }
}
