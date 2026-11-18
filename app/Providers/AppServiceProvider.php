<?php

namespace App\Providers;

use App\Contracts\EmailProviderInterface;
use App\Services\Providers\GmailProviderService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EmailProviderInterface::class, fn () => new GmailProviderService);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(12)->max(255));
    }
}
