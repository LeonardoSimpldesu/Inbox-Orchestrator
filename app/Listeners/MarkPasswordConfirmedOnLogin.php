<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;

class MarkPasswordConfirmedOnLogin
{
    /**
     * A login where the password was just typed (directly or before the 2FA
     * challenge) counts as a fresh password confirmation; a remember-me cookie
     * login does not, so sensitive pages still ask for the password.
     */
    public function handle(Login $event): void
    {
        if (request()->routeIs('login.store', 'two-factor.login.store')) {
            request()->session()->put('auth.password_confirmed_at', time());
        }
    }
}
